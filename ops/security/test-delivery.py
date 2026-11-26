"""Local contract tests with synthetic files. Requires Gitleaks on PATH."""
import gzip
import hashlib
import io
import json
import os
from pathlib import Path
import subprocess
import runpy
import sys
import tarfile
import tempfile
import unittest
from unittest.mock import patch

HERE = Path(__file__).resolve().parent
CHECK = [sys.executable, str(HERE / "delivery-check.py")]
GATE = [sys.executable, str(HERE / "ci-gate.py")]


def tar_bytes(files):
    buffer = io.BytesIO()
    with tarfile.open(fileobj=buffer, mode="w") as archive:
        for name, data in files.items():
            info = tarfile.TarInfo(name)
            info.size = len(data)
            archive.addfile(info, io.BytesIO(data))
    return buffer.getvalue()


def oci(path, layers, *, corrupt=False):
    blobs = {}

    def add(data, kind):
        key = hashlib.sha256(data).hexdigest()
        blobs["blobs/sha256/" + key] = data
        return {"digest": "sha256:" + key, "size": len(data), "mediaType": kind}

    kind = "application/vnd.oci.image."
    layer_data = [gzip.compress(tar_bytes(layer)) for layer in layers]
    layer_refs = [add(data, kind + "layer.v1.tar+gzip") for data in layer_data]
    manifest = {"schemaVersion": 2, "layers": layer_refs,
                "config": add(b'{"config":{}}', kind + "config.v1+json")}
    image = add(json.dumps(manifest).encode(), kind + "manifest.v1+json")
    if corrupt:
        key = "blobs/sha256/" + layer_refs[0]["digest"].split(":")[1]
        blobs[key] = b"x" * len(blobs[key])
    path.write_bytes(tar_bytes({"index.json": json.dumps({"manifests": [image]}).encode(),
                                "oci-layout": b'{"imageLayoutVersion":"1.0.0"}', **blobs}))
    return image["digest"]


class DeliveryTest(unittest.TestCase):
    def test_git_range(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            env = {
                **os.environ,
                "GIT_CONFIG_GLOBAL": os.devnull,
                "GIT_CONFIG_SYSTEM": os.devnull,
                "GIT_AUTHOR_NAME": "Synthetic test",
                "GIT_COMMITTER_NAME": "Synthetic test",
                "GIT_AUTHOR_EMAIL": "synthetic@users.noreply.github.com",
                "GIT_COMMITTER_EMAIL": "synthetic@users.noreply.github.com",
            }

            def git(*args, **overrides):
                return subprocess.check_output(
                    ["git", *args], cwd=root, env={**env, **overrides}, stderr=subprocess.PIPE,
                ).decode().strip()

            def check(base, head, action="git-range", stdin=None):
                args = [base, head] if action == "git-range" else ["unused"]
                return subprocess.run(
                    [*CHECK, action, *args], cwd=root, env=env, input=stdin, capture_output=True,
                )

            def pre_push(base, head):
                stdin = f"refs/heads/topic {head} refs/heads/topic {base}\n".encode()
                return check(base, head, "pre-push", stdin)

            git("init", "-q")
            git("commit", "--allow-empty", "-qm", "Synthetic base")
            base = git("rev-parse", "HEAD")
            git("commit", "--allow-empty", "-qm", "Clean synthetic change")
            clean = git("rev-parse", "HEAD")
            self.assertEqual(check(base, clean).returncode, 0)
            self.assertEqual(pre_push(base, clean).returncode, 0)

            for field in ("GIT_AUTHOR_EMAIL", "GIT_COMMITTER_EMAIL"):
                git("reset", "--hard", clean)
                address = "synthetic@example.invalid"
                git("commit", "--allow-empty", "-qm", "Synthetic metadata", **{field: address})
                git("commit", "--allow-empty", "-qm", "Clean head")
                head = git("rev-parse", "HEAD")
                for result in (check(base, head), pre_push(base, head)):
                    self.assertNotEqual(result.returncode, 0)
                    self.assertIn(b"not a GitHub noreply address", result.stderr)
                    self.assertNotIn(address.encode(), result.stderr)

            git("reset", "--hard", clean)
            export = root / "synthetic.csv"
            export.write_text("email,phone\nsynthetic@example.invalid,000000000\n")
            git("add", export.name)
            git("commit", "-qm", "Synthetic export")
            git("rm", export.name)
            git("commit", "-qm", "Remove synthetic export")
            head = git("rev-parse", "HEAD")
            for result in (check(base, head), pre_push(base, head)):
                self.assertNotEqual(result.returncode, 0)
                self.assertIn(b"possible personal-data export", result.stderr)

    def test_ci_results(self):
        for result in ("success", "failure", "skipped", "cancelled", "neutral", ""):
            with self.subTest(result=result):
                for position in range(6):
                    results = ["success"] * 6
                    results[position] = result
                    run = subprocess.run([*GATE, *results], capture_output=True)
                    self.assertEqual(run.returncode == 0, result == "success")
        self.assertNotEqual(subprocess.run([*GATE, "success"], capture_output=True).returncode, 0)

    def test_production_ci_gate(self):
        main = runpy.run_path(str(HERE / "require-production-ci.py"))["main"]
        sha = "a" * 40
        fake_runs = json.dumps([{"databaseId": 10, "headSha": "b" * 40},
                                {"databaseId": 11, "headSha": sha}])
        for head, results in ((sha, ["success"]), (sha, []), (sha, ["skipped"]),
                              (sha, ["success", "success"]), ("b" * 40, ["success"])):
            payload = json.dumps({"headSha": head, "jobs": [
                {"name": "CI required", "conclusion": result} for result in results
            ]})
            with self.subTest(head=head, results=results), patch.dict(os.environ, {
                "GITHUB_REPOSITORY": "synthetic/myinvoice", "SOURCE_SHA": sha,
            }), patch.dict(main.__globals__) as helpers:
                calls = []

                def gh(*args):
                    calls.append(args)
                    return {"list": fake_runs, "watch": "", "view": payload}[args[1]]

                helpers["run_gh"] = gh
                if head == sha and results == ["success"]:
                    main()
                else:
                    with self.assertRaises(SystemExit):
                        main()
                self.assertIn("--event", calls[0])
                self.assertEqual(calls[1][:3], ("run", "watch", "11"))
                self.assertEqual(calls[2][:3], ("run", "view", "11"))

    def test_image(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            context = root / "context"
            context.mkdir()
            (context / "README.md").write_text("synthetic")
            path = root / "image.tar"
            clean = {"var/www/html/README.md": b"synthetic"}
            expected = oci(path, [clean])
            run = subprocess.run([*CHECK, "image", path, context], capture_output=True)
            self.assertEqual(run.returncode, 0, run.stderr)
            self.assertEqual(run.stdout.decode().strip(), expected)

            for name, payload, corrupt in [
                ("README.md", b"-- MariaDB dump\n", False),
                (".env", b"TEST=synthetic", False),
                ("README.md", gzip.compress(b"synthetic"), False),
                ("api/vendor/opaque.dat", b"synthetic\0binary", False),
                ("README.md", b"synthetic", True),
            ]:
                with self.subTest(name=name, corrupt=corrupt):
                    oci(path, [{"var/www/html/" + name: payload},
                               {"var/www/html/.wh." + name: b""}, clean], corrupt=corrupt)
                    run = subprocess.run([*CHECK, "image", path, context], capture_output=True)
                    self.assertNotEqual(run.returncode, 0)
                    self.assertEqual(run.stdout, b"")

    def test_index(self):
        with tempfile.TemporaryDirectory() as directory:
            env = {**os.environ, "GIT_CONFIG_GLOBAL": os.devnull, "GIT_CONFIG_SYSTEM": os.devnull}
            subprocess.run(["git", "init", "-q", directory], check=True, env=env)
            file = Path(directory) / "README.md"
            file.write_text("synthetic benign content")
            subprocess.run(["git", "add", "--", file.name], cwd=directory, check=True, env=env)
            result = subprocess.run([*CHECK, "pre-push", "unused"], cwd=directory,
                                    input=b"", capture_output=True, env=env)
            self.assertEqual(result.returncode, 0, result.stderr)
            synthetic_token = "ghp_" + hashlib.sha256(b"local synthetic test only").hexdigest()[:36]
            file.write_text("token = " + synthetic_token)
            subprocess.run(["git", "add", "--", file.name], cwd=directory, check=True, env=env)
            result = subprocess.run([*CHECK, "pre-push", "unused"], cwd=directory,
                                    input=b"", capture_output=True, env=env)
            self.assertIn(b"secret scanner finding", result.stderr)
            self.assertNotIn(synthetic_token.encode(), result.stderr)
            file.write_text("-- MySQL dump\n")
            subprocess.run(["git", "add", "--", file.name], cwd=directory, check=True, env=env)
            file.write_text("clean working tree content")
            result = subprocess.run([*CHECK, "pre-push", "unused"], cwd=directory,
                                    input=b"", capture_output=True, env=env)
            self.assertNotEqual(result.returncode, 0)

            template = Path(directory) / "cfg.sample.php"
            file.write_text("clean working tree content")
            subprocess.run(["git", "add", "--", file.name], cwd=directory, check=True, env=env)
            for address, expected_code in (
                ("smtp-user@example.invalid", 0),
                ("synthetic@internal.example", 1),
            ):
                template.write_text(f"<?php return ['from' => '{address}'];\n")
                subprocess.run(["git", "add", "--", template.name], cwd=directory, check=True, env=env)
                result = subprocess.run([*CHECK, "index"], cwd=directory, capture_output=True, env=env)
                self.assertEqual(result.returncode, expected_code, result.stderr)
                self.assertNotIn(address.encode(), result.stderr)


if __name__ == "__main__":
    unittest.main()
