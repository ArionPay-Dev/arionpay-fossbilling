"""Build the FOSSBilling package: copies the ArionPay PHP SDK next to the adapter, then zips library/.
   python3 build.py  ->  dist/arionpay-fossbilling.zip  (unzip into the FOSSBilling folder)"""
import os, shutil, zipfile, pathlib
HERE = pathlib.Path(__file__).resolve().parent
SDK = HERE.parent / "arionpay-php"
lib = HERE / "library" / "Payment" / "Adapter" / "ArionPay" / "arionpay-php"
if lib.exists():
    shutil.rmtree(lib)
(lib / "src").mkdir(parents=True)
for f in ("autoload.php", "LICENSE"):
    shutil.copy(SDK / f, lib / f)
for f in sorted((SDK / "src").glob("*.php")):
    shutil.copy(f, lib / "src" / f.name)
(HERE / "dist").mkdir(exist_ok=True)
out = HERE / "dist" / "arionpay-fossbilling.zip"
with zipfile.ZipFile(out, "w", zipfile.ZIP_DEFLATED) as z:
    for root, dirs, files in os.walk(HERE / "library"):
        dirs.sort()
        for name in sorted(files):
            p = pathlib.Path(root) / name
            z.write(p, str(p.relative_to(HERE)))
    z.write(HERE / "README.md", "ARIONPAY-README.md") if (HERE / "README.md").exists() else None
print(out, os.path.getsize(out), "bytes")
