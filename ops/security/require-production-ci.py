"""Require a successful production push run and its exact success gate."""
import json
import os
import re
import subprocess
import sys
import time


def run_gh(*args):
    return subprocess.run(["gh", *args], check=True, capture_output=True, text=True).stdout


def main():
    repo = os.environ["GITHUB_REPOSITORY"]
    sha = os.environ["SOURCE_SHA"]
    if not re.fullmatch(r"[0-9a-f]{40}", sha):
        sys.exit("Invalid production commit SHA")
    run_id = None
    for _ in range(60):
        runs = json.loads(run_gh("run", "list", "--repo", repo, "--workflow", "ci.yml",
                                 "--branch", "production", "--commit", sha, "--event", "push",
                                 "--json", "databaseId,headSha"))
        run_id = next((str(run["databaseId"]) for run in runs if run["headSha"] == sha), None)
        if run_id:
            break
        time.sleep(10)
    if run_id is None:
        sys.exit("No CI push run for the exact production commit")
    run_gh("run", "watch", run_id, "--repo", repo, "--exit-status")
    run = json.loads(run_gh("run", "view", run_id, "--repo", repo, "--json", "headSha,jobs"))
    results = [job["conclusion"] for job in run["jobs"] if job["name"] == "CI required"]
    if run["headSha"] != sha or results != ["success"]:
        sys.exit("Production CI required job did not run exactly once and succeed")


if __name__ == "__main__":
    try:
        main()
    except (KeyError, ValueError, OSError, subprocess.CalledProcessError):
        sys.exit("Unable to verify production CI")
