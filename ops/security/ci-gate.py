"""Validate the complete set of mandatory CI job results."""
import sys

required = ("delivery-data", "backend", "frontend", "local-development-contract",
            "netcup-deploy-contract", "local-development-powershell")


def main():
    results = sys.argv[1:]
    if len(results) != len(required):
        sys.exit("Missing or unexpected mandatory CI results")
    failures = [job for job, result in zip(required, results) if result != "success"]
    if failures:
        sys.exit("Mandatory CI failed or did not run: " + ", ".join(failures))


if __name__ == "__main__":
    main()
