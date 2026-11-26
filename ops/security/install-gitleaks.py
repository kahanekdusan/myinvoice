"""Install the checksum-pinned scanner into a caller-owned directory."""
import hashlib
import io
import pathlib
import platform
import sys
import tarfile
import urllib.request
import zipfile

version = "8.30.1"
platforms = {
    ("Linux", "x86_64"): ("linux_x64", "551f6fc83ea457d62a0d98237cbad105af8d557003051f41f3e7ca7b3f2470eb"),
    ("Linux", "aarch64"): ("linux_arm64", "e4a487ee7ccd7d3a7f7ec08657610aa3606637dab924210b3aee62570fb4b080"),
    ("Darwin", "x86_64"): ("darwin_x64", "dfe101a4db2255fc85120ac7f3d25e4342c3c20cf749f2c20a18081af1952709"),
    ("Windows", "AMD64"): ("windows_x64", "d29144deff3a68aa93ced33dddf84b7fdc26070add4aa0f4513094c8332afc4e"),
    ("Windows", "ARM64"): ("windows_arm64", "b95f5e4f5c425cedca7ee203d9afd29597e692c4924a12ed42f970537c72cc0f"),
    ("Darwin", "arm64"): ("darwin_arm64", "b40ab0ae55c505963e365f271a8d3846efbc170aa17f2607f13df610a9aeb6a5"),
}
variant, expected = platforms[(platform.system(), platform.machine())]
extension = "zip" if variant.startswith("windows_") else "tar.gz"
url = f"https://github.com/gitleaks/gitleaks/releases/download/v{version}/gitleaks_{version}_{variant}.{extension}"
with urllib.request.urlopen(url, timeout=60) as response:
    data = response.read()
if hashlib.sha256(data).hexdigest() != expected:
    sys.exit("Scanner checksum mismatch")
target = pathlib.Path(sys.argv[1])
if extension == "zip":
    with zipfile.ZipFile(io.BytesIO(data)) as archive:
        target.with_suffix(".exe").write_bytes(archive.read("gitleaks.exe"))
else:
    with tarfile.open(fileobj=io.BytesIO(data)) as archive:
        target.write_bytes(archive.extractfile("gitleaks").read())
    target.chmod(0o755)
