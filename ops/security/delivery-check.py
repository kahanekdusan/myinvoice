"""Fail-closed local Git and container delivery checks. Never print payloads."""
import csv
from fnmatch import fnmatchcase
import gzip
import io
import lzma
import hashlib
import json
import os
from pathlib import Path, PurePosixPath
import re
import shutil
import subprocess
import sys
import tarfile
import tempfile

MAX_FILE = 256 * 1024 * 1024
APP = "var/www/html/"
CONFIG_STUB = b"<?php return [];\n"
SAMPLE_ENV = {"ops/docker/local.env.example", "tools/money-s3-import/.env.example"}
APP_ROOTS = {"api", "db", "docker", "manual", "styles", "web"}
APP_FILES = {".htaccess", "web.config", "VERSION", "LICENSE", "README.md",
             "CHANGELOG.md", "cfg.sample.php", "docker-entrypoint.sh", "Dockerfile",
             "Dockerfile.alpine", ".dockerignore"}
APP_TOOLS = {"tools/generateManualHtml.php", "tools/exportManualToPdf.php",
             "tools/generateDockerCrontab.php", "tools/md2pdf.config.php"}
ARCHIVE_SUFFIX = re.compile(r"\.(?:zip|rar|7z|tar|gz|xz|bz2|zst|tgz|phar|xlsx?|ods|sqlite3?|db|dump|bak|backup|sqlitedb|pfx|p12|pem|key|jks|pgp|gpg|asc|log|eml)$", re.I)
ARCHIVE_MAGIC = (b"PK\x03\x04", b"7z\xbc\xaf\x27\x1c", b"Rar!", b"\x1f\x8b", b"\xfd7zXZ\x00",
                 b"BZh", b"SQLite format 3\0", b"\x28\xb5\x2f\xfd", b"%PDF-", b"\xd0\xcf\x11\xe0", b"Salted__", b"PK\x05\x06", b"PK\x07\x08")
DUMP = re.compile(rb"(?im)^\s*(?:-- (?:MariaDB|MySQL|PostgreSQL).*dump|LOCK TABLES\s+`|COPY\s+\S+\s+\([^\n]+\)\s+FROM stdin;)")
PRIVATE_KEY = re.compile(
    rb"(?m)^-----BEGIN (?:[A-Z0-9]+ )*PRIVATE KEY-----[ \t]*\r?\n"
    rb"(?:[A-Za-z0-9+/=]{16,}\r?\n)+-----END (?:[A-Z0-9]+ )*PRIVATE KEY-----"
)
# Detect common tabular exports by combinations of header names.
PII_FIELDS = {"email", "e-mail", "main_email", "phone", "telephone", "first_name",
              "last_name", "street", "address", "iban", "account_number", "rodne_cislo"}
EMAIL = re.compile(rb"(?i)[a-z0-9_.+%-]+@([a-z0-9.-]+\.[a-z]{2,})")
EXAMPLE_DOMAINS = {b"example.com", b"example.net", b"example.org"}
NOREPLY = re.compile(rb"(?:[^@\s]+@users\.noreply\.github\.com|noreply@github\.com)")
HEX = re.compile(r"[0-9a-f]{64}")

# Exact public constants misidentified by the generic key rule in the base
# distribution and Composer MIME map. A changed line is scanned normally.
PUBLIC_CONSTANTS = {
    "var/www/html/api/vendor/symfony/mime/MimeTypes.php": {
        "f6b242a7c4738971b906d51418e530c93f95b5a206c503488d371bba70c12f43",
    },
    "usr/include/linux/nl80211.h": {
        "7e276c191b3bffcc59ae008a8deeb2840f44ac5187cf335926419ce931c74988",
    },
    "usr/lib/*/perl/*/CORE/cop.h": {
        "96568ed58624339349a1a0bafaf636e23e03119c86f8aa084302da9aa762223c",
    },
    "usr/local/include/php/ext/hash/xxhash/xxhash.h": {
        "40675cca297b4a647b08f5d7334496e51d05b4a3bd1b0ca8160db2811b02460b",
        "7eea4013bc30aa348968a0f241c3190f17eee941a00007ea6da98dcf86947aba",
        "3f90e2ca9a9b9f0a430b1aef4cbd48363d5f4a2bf2b258caac2a01d54de6cde9",
        "2534967e45553e4c480ed661a7a71312773514558eeef2c5efdf9c77bf37d153",
    },
}

MPDF_ASSET_DIGESTS = {
    "iccprofiles/sRGB_IEC61966-2-1.icc": "b3599c68b79236e5ce69d8dd22178157553631c5fe829130602cde98d8764790",
    "linebrdictK.dat": "8f5e4c12bf0d5fde4a4979061ed610e2ceac6ff2688492b8ad740fd5ca67a157",
    "linebrdictL.dat": "71688deb301e0833e49fd96e5a9f2bdcbbc81229a9629316c9ee7a48c36f1d78",
    "linebrdictT.dat": "c25db6232321724f7bbd92b143c4adbe68a4ea53d70a6f3830b905fe2ec8c967",
}


class Blocked(Exception):
    pass


def require(ok, category):
    if not ok:
        raise Blocked(category)


def command(args, **kwargs):
    return subprocess.check_output(args, stderr=subprocess.DEVNULL, **kwargs)


def clean_path(name):
    require(not name.startswith("/") and "\\" not in name and
            ".." not in name.split("/") and "\0" not in name, "unsafe path")
    return str(PurePosixPath(name))


def check_path(path):
    parts = path.lower().split("/")
    if path.startswith("api/vendor/symfony/http-foundation/Session/Storage/") and path.endswith(".php"):
        parts.remove("storage")
    leaf = parts[-1]
    require(not ({".git", "logs", "backups", "private", "storage", "node_modules"} & set(parts)),
            "forbidden path")
    require(path in SAMPLE_ENV or path == "api/vendor/illuminate/support/Env.php" or not any(re.match(r"^\.?env(?:[._-]|$)|\.env(?:[._-]|$)", p)
                                          for p in parts), "environment configuration")
    require(not re.fullmatch(r"cfg(?:[._-].+)?\.php", leaf) or leaf == "cfg.sample.php",
            "runtime configuration")
    require(not ARCHIVE_SUFFIX.search(leaf), "restricted file format")


def check_data(path, data):
    require(not DUMP.search(data), "database dump")
    require(not PRIVATE_KEY.search(data), "private key")
    if path == "cfg.sample.php" or path in SAMPLE_ENV:
        for domain in EMAIL.findall(data):
            domain = domain.lower()
            require(domain in EXAMPLE_DOMAINS or domain.endswith((b".invalid", b".test")),
                    "non-example email address in configuration template")
    if path.lower().endswith(".sql"):
        require(path.startswith("db/migrations/"),
                "SQL outside reviewed migrations")
        require(not re.search(rb"(?is)\bINSERT\s+(?:IGNORE\s+)?INTO\s+`?(?:clients|supplier|users|employee[s]?|bank_transactions|invoices)`?\s*(?:\([^;]*?\))?\s*VALUES\b", data),
                "customer or operational SQL rows")
    if path.lower().endswith((".csv", ".tsv")):
        try:
            first = data.decode("utf-8-sig").splitlines()[0]
        except (UnicodeError, IndexError):
            raise Blocked("unverified tabular content") from None
        fields = {field.strip().lower() for field in next(csv.reader([first.replace(";", ",").replace("\t", ",")]))}
        require(len(fields & PII_FIELDS) < 2, "possible personal-data export")
    if path.lower().endswith((".json", ".jsonl", ".ndjson")) and path not in {
        "web/src/i18n/cs.json", "web/src/i18n/en.json",
    }:
        fields = {m.decode().lower() for m in re.findall(rb'"([A-Za-z_-]+)"\s*:', data)}
        require(len(fields & PII_FIELDS) < 2, "possible personal-data export")
    require(not data.startswith(ARCHIVE_MAGIC) and data[257:262] != b"ustar",
            "embedded archive or database")


class Scan:
    def __init__(self, directory):
        self.directory = Path(directory)
        self.count = 0

    def add(self, data):
        self.count += 1
        (self.directory / f"{self.count}.txt").write_bytes(data)

    def finish(self):
        scanner = shutil.which("gitleaks")
        require(scanner, "required secret scanner is unavailable")
        if self.count:
            env = {key: value for key, value in os.environ.items()
                   if key not in {"GITLEAKS_CONFIG", "GITLEAKS_CONFIG_TOML"}}
            result = subprocess.run(
                [scanner, "dir", "--no-banner", "--redact=100", "--ignore-gitleaks-allow",
                 "--gitleaks-ignore-path", os.devnull, "--max-decode-depth", "2", "--max-target-megabytes", "0", str(self.directory)],
                stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, cwd=self.directory, env=env,
            )
            require(result.returncode == 0, "secret scanner finding or failure")


def git_paths(commit):
    return command(["git", "diff-tree", "--root", "-m", "--no-commit-id", "--name-only",
                    "--diff-filter=ACMRT", "--no-renames", "-r", "-z", commit]).split(b"\0")


def check_source_blob(path, data, scan):
    check_data(path, data)
    if b"\0" not in data:
        scan.add(data)
        return
    check_binary(path, data)


def check_binary(path, data):
    header = {
        ".png": (b"\x89PNG\r\n\x1a\n",), ".jpg": (b"\xff\xd8\xff",),
        ".jpeg": (b"\xff\xd8\xff",), ".webp": (b"RIFF",),
        ".ttf": (b"\0\x01\0\0", b"OTTO"), ".woff": (b"wOFF",), ".woff2": (b"wOF2",),
    }
    suffix = PurePosixPath(path).suffix.lower()
    require(suffix in header and data.startswith(header[suffix])
            and (suffix != ".webp" or data[8:12] == b"WEBP"), "unverified binary application file")


def check_git_object(path, ref, scan):
    path = clean_path(os.fsdecode(path))
    entry = (command(["git", "ls-files", "--stage", "-z", "--", ":(literal)" + path]) if ref == ":" else
             command(["git", "ls-tree", "-rz", ref, "--", ":(literal)" + path]))
    if not entry:
        return
    require(entry.count(b"\0") == 1 and entry.startswith((b"100644 ", b"100755 ")),
            "unsupported index or tree entry")
    check_path(path)
    oid = entry.split(b"\t", 1)[0].split()[1 if ref == ":" else 2].decode()
    size = int(command(["git", "cat-file", "-s", oid]))
    require(size <= MAX_FILE, "oversized Git object")
    data = command(["git", "cat-file", "blob", oid])
    check_source_blob(path, data, scan)


def commit_index(scan):
    for role in ("AUTHOR", "COMMITTER"):
        ident = command(["git", "var", "GIT_" + role + "_IDENT"]).strip()
        address = re.search(rb"<([^<>]+)> [0-9]+ [+-][0-9]{4}$", ident)
        require(address and NOREPLY.fullmatch(address[1]),
                "effective Git author or committer email is not a GitHub noreply address")
    git_index(scan)


def git_index(scan):
    for path in command(["git", "diff", "--cached", "--name-only", "--diff-filter=ACMRT",
                         "--no-renames", "-z"]).split(b"\0"):
        if path:
            check_git_object(path, ":", scan)


def git_commits(head, excludes, seen, scan):
    for commit in command(["git", "rev-list", head, *excludes]).decode().splitlines():
        if commit in seen:
            continue
        seen.add(commit)
        meta = command(["git", "show", "-s", "--format=%ae%x00%ce%x00%B", commit])
        author, committer, message = meta.split(b"\0", 2)
        for address in (author, committer):
            require(NOREPLY.fullmatch(address),
                    "commit author or committer email is not a GitHub noreply address")
        scan.add(message)
        for path in set(git_paths(commit)) - {b""}:
            check_git_object(path, commit, scan)


def git_scan(remote, scan):
    git_index(scan)
    seen = set()
    # Remote refs are queried only for a new ref. Missing local remote objects
    # are ignored as exclusions, so they cannot hide an outgoing commit.
    remote_commits = None
    for line in sys.stdin:
        _, local_oid, _, remote_oid = line.split()
        if set(local_oid) == {"0"}:
            continue
        while command(["git", "cat-file", "-t", local_oid]).strip() == b"tag":
            tag = command(["git", "cat-file", "tag", local_oid])
            header = tag.split(b"\n\n", 1)[0]
            tagger = re.search(rb"(?m)^tagger [^\n]*<([^<>]+)> [0-9]+ [+-][0-9]{4}$", header)
            require(tagger and NOREPLY.fullmatch(tagger[1]),
                    "tagger email is not a GitHub noreply address")
            scan.add(tag)
            local_oid = tag.splitlines()[0].removeprefix(b"object ").decode()
        head = command(["git", "rev-parse", "--verify", local_oid + "^{commit}"]).decode().strip()
        if set(remote_oid) == {"0"}:
            if remote_commits is None:
                remote_commits = []
                for ref in command(["git", "ls-remote", "--", remote]).splitlines():
                    oid = ref.split()[0].decode()
                    proc = subprocess.run(["git", "rev-parse", "--verify", oid + "^{commit}"],
                                          capture_output=True)
                    if proc.returncode == 0:
                        remote_commits.append("^" + proc.stdout.decode().strip())
            excludes = remote_commits
        else:
            excludes = ["^" + remote_oid + "^{commit}"]
        git_commits(head, excludes, seen, scan)


def git_range(base, head, scan):
    require(all(re.fullmatch(r"[0-9a-f]{40}", sha) for sha in (base, head)),
            "unverified Git range")
    git_commits(head + "^{commit}", ["^" + base + "^{commit}"], set(), scan)


def context(destination, scan):
    target = Path(destination)
    require(not target.resolve().is_relative_to(Path.cwd()), "build context inside checkout")
    target.mkdir()  # Must be fresh.
    for entry in command(["git", "ls-tree", "-rz", "HEAD"]).split(b"\0")[:-1]:
        meta, raw_path = entry.split(b"\t", 1)
        path = clean_path(os.fsdecode(raw_path))
        root = path.split("/", 1)[0]
        allowed = path in APP_FILES or path in APP_TOOLS or root in APP_ROOTS
        if not allowed or path.startswith(("api/tests/", "web/tests/")):
            continue
        require(meta.startswith((b"100644 ", b"100755 ")), "unsupported context file")
        check_path(path)
        data = command(["git", "cat-file", "blob", meta.split()[2].decode()])
        check_source_blob(path, data, scan)
        file = target / path
        file.parent.mkdir(parents=True, exist_ok=True)
        file.write_bytes(data)
        file.chmod(0o755 if meta.startswith(b"100755 ") else 0o644)


def check_system_file(path, data, scan, php_source_digest):
    require(not DUMP.search(data) and not PRIVATE_KEY.search(data),
            "dump or private key outside application")
    leaf = PurePosixPath(path).name
    require(not leaf.lower().startswith(".env") and not path.startswith(("data/", "root/.ssh/")),
            "runtime data outside application")
    if path == "usr/src/php.tar.xz":
        # The official PHP base verifies this public source archive against
        # PHP_SHA256. It is present in a lower layer even after source cleanup.
        require(hashlib.sha256(data).hexdigest() == php_source_digest,
                "unexpected PHP base source archive")
        return
    formats = ((b"\x1f\x8b", lambda data: gzip.GzipFile(fileobj=data)),
               (b"\xfd7zXZ\x00", lzma.LZMAFile))
    for magic, reader in formats:
        if data.startswith(magic):
            apk_metadata = path == "lib/apk/db/scripts.tar.gz" or bool(
                re.fullmatch(r"var/cache/apk/APKINDEX\.[0-9a-f]+\.tar\.gz", path))
            require(apk_metadata or path.startswith(("usr/share/", "usr/lib/", "var/log/apt/")),
                    "compressed file outside approved system paths")
            with reader(io.BytesIO(data)) as stream:
                payload = stream.read(MAX_FILE + 1)
            require(len(payload) <= MAX_FILE, "oversized compressed system file")
            if apk_metadata:
                with tarfile.open(fileobj=io.BytesIO(payload), mode="r:") as archive:
                    for member in archive:
                        name = clean_path(member.name)
                        require(member.isfile() or member.isdir(), "unsupported APK metadata member")
                        if member.isfile():
                            content = archive.extractfile(member).read()
                            check_data(name, content)
                            if name == "APKINDEX" and path.startswith("var/cache/apk/"):
                                # Public package relations and source commit hashes are
                                # structured catalog data, not free-form credentials.
                                content = re.sub(
                                    rb"(?m)^(?:[pDi]:[ A-Za-z0-9_.+@:/=<>~!%-]+|c:[0-9a-f]{40,64})$",
                                    b"", content,
                                )
                            if b"\0" not in content:
                                scan.add(content)
            else:
                check_data("system-file", payload)
                if b"\0" not in payload:
                    scan.add(payload)
            return
    require(not data.startswith(ARCHIVE_MAGIC) and data[257:262] != b"ustar",
            "unverified archive outside application")


def check_layer(fileobj, scan, approved, php_source_digest):
    with tarfile.open(fileobj=fileobj, mode="r|*") as layer:
        for entry in layer:
            path = clean_path(entry.name)
            if not entry.isfile():
                if path.startswith(APP):
                    require(entry.isdir(), "unexpected application layer entry")
                continue
            require(entry.size <= MAX_FILE, "oversized image member")
            data = layer.extractfile(entry).read()
            require(len(data) == entry.size, "truncated image member")
            if path.startswith(APP):
                relative = path[len(APP):]
                if PurePosixPath(relative).name.startswith(".wh."):
                    require(entry.size == 0, "invalid OCI whiteout")
                    continue
                if relative == "cfg.php":
                    require(data == CONFIG_STUB, "runtime configuration in image")
                    continue
                if relative == "manual/manual.pdf":
                    require(data.startswith(b"%PDF-") and b"/EmbeddedFile" not in data
                            and b"/JavaScript" not in data and b"/Encrypt" not in data,
                            "unexpected manual PDF content")
                    scan.add(command(["pdftotext", "-", "-"], input=data))
                    continue
                require(relative in approved or relative.startswith(
                    ("api/vendor/", "web/dist/", "manual/generated/")),
                    "unexpected application image file")
                check_path(relative)
                check_data(relative, data)
                if b"\0" in data:
                    data_root = "api/vendor/mpdf/mpdf/data/"
                    expected = MPDF_ASSET_DIGESTS.get(relative.removeprefix(data_root))
                    if relative.startswith(data_root) and expected:
                        require(hashlib.sha256(data).hexdigest() == expected, "unexpected mPDF binary asset")
                    else:
                        check_binary(relative, data)
            else:
                check_system_file(path, data, scan, php_source_digest)
            if b"\0" not in data and not data.startswith(ARCHIVE_MAGIC):
                if path == "usr/include/linux/netfilter/nf_tables.h":
                    # Public Linux enum identifiers, with no assigned values.
                    data = re.sub(rb"(?m)^([ \t]+)NFTA?_[A-Z0-9_]+(,?[ \t]*)$",
                                  rb"\1PUBLIC_ENUM\2", data)
                for pattern, hashes in PUBLIC_CONSTANTS.items():
                    if fnmatchcase(path, pattern):
                        data = b"".join(line for line in data.splitlines(keepends=True)
                                        if hashlib.sha256(line.strip()).hexdigest() not in hashes)
                scan.add(data)


def image(filename, context_dir, scan):
    approved = {p.relative_to(context_dir).as_posix()
                for p in Path(context_dir).rglob("*") if p.is_file()}
    with tarfile.open(filename, mode="r:") as archive:
        members = archive.getmembers()
        names = {clean_path(member.name): member for member in members}
        require(len(names) == len(members), "duplicate OCI archive member")
        require(all(x.isfile() or x.isdir() for x in members), "unsupported OCI member")

        def descriptor(desc, *, read=True):
            value = desc["digest"].removeprefix("sha256:")
            require(desc["digest"] == "sha256:" + value and HEX.fullmatch(value), "unsupported digest")
            member = names["blobs/sha256/" + value]
            require(member.isfile() and member.size == desc["size"], "OCI size mismatch")
            with archive.extractfile(member) as handle:
                actual = hashlib.sha256()
                while chunk := handle.read(1024 * 1024):
                    actual.update(chunk)
            require(actual.hexdigest() == value, "OCI digest mismatch")
            return archive.extractfile(member).read() if read else archive.extractfile(member)

        index = json.load(archive.extractfile(names["index.json"]))
        manifests = index["manifests"]
        require(len(manifests) == 1, "expected a single platform manifest")
        item = manifests[0]
        require(item["mediaType"] == "application/vnd.oci.image.manifest.v1+json",
                "unsupported manifest type")
        manifest = json.loads(descriptor(item))
        config = descriptor(manifest["config"])
        # The official PHP image advertises public OpenPGP fingerprints here.
        scan.add(re.sub(rb"GPG_KEYS=[0-9A-F]{40}(?: [0-9A-F]{40})*(?=[\"\\ ])", b"GPG_KEYS=", config))
        config_env = json.loads(config).get("config", {}).get("Env", [])
        php_source_digest = next((item[11:] for item in config_env if item.startswith("PHP_SHA256=")), None)
        layer_ids = []
        for layer in manifest["layers"]:
            require(layer["mediaType"] in {"application/vnd.oci.image.layer.v1.tar",
                                            "application/vnd.oci.image.layer.v1.tar+gzip"},
                    "unsupported layer compression")
            with descriptor(layer, read=False) as handle:
                check_layer(handle, scan, approved, php_source_digest)
            layer_ids.append(layer["digest"])
        allowed = {"index.json", "oci-layout", "blobs", "blobs/sha256"}
        allowed.update("blobs/sha256/" + value.split(":")[1]
                       for value in [item["digest"], manifest["config"]["digest"], *layer_ids])
        require(names.keys() <= allowed and layer_ids, "unexpected OCI metadata or blobs")
    return item["digest"]


def main():
    with tempfile.TemporaryDirectory() as directory:
        scan = Scan(directory)
        action, *args = sys.argv[1:]
        digest = None
        if action == "pre-push":
            git_scan(*args, scan)
        elif action == "index":
            git_index(scan)
        elif action == "pre-commit":
            commit_index(scan)
        elif action == "git-range":
            git_range(*args, scan)
        elif action == "context":
            context(*args, scan)
        elif action == "image":
            digest = image(*args, scan)
        else:
            raise Blocked("unknown delivery check")
        scan.finish()
        if digest:
            print(digest)


if __name__ == "__main__":
    try:
        main()
    except Blocked as error:
        sys.exit(f"Delivery blocked: {error}")
    except Exception:
        sys.exit("Delivery blocked: unable to verify input or run a required tool")
