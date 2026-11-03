"""File inventory: hash, sniffed media type and applicable inspectors."""
import hashlib
import os
import zipfile

from .limits import LimitError, MAX_DECOMPRESSION_RATIO, MAX_FILE_BYTES, MAX_UNCOMPRESSED_BYTES

INSPECTORS = {
    "message/rfc822": ["eml"],
    "application/vnd.ms-outlook": ["msg"],
    "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet": ["xlsx"],
    "application/vnd.ms-excel": ["xls"],
    "application/pdf": ["pdf"],
    "application/vnd.openxmlformats-officedocument.wordprocessingml.document": ["docx"],
    "image/png": ["image"], "image/jpeg": ["image"], "image/gif": ["image"],
    "image/bmp": ["image"], "image/tiff": ["image"], "image/webp": ["image"],
    "text/csv": ["text"], "text/plain": ["text"],
}
_OLE = b"\xd0\xcf\x11\xe0\xa1\xb1\x1a\xe1"
_HEADER_KEYS = (b"received:", b"from:", b"to:", b"subject:", b"date:", b"mime-version:",
                b"message-id:", b"return-path:", b"content-type:", b"x-")


def check_zip(path):
    """Refuse zip bombs: total size and ratio guard (does not extract)."""
    size = os.path.getsize(path)
    with zipfile.ZipFile(path) as z:
        total = sum(i.file_size for i in z.infolist())
    if total > MAX_UNCOMPRESSED_BYTES:
        raise LimitError(f"archive expands to {total} bytes (limit {MAX_UNCOMPRESSED_BYTES})")
    if size and total / size > MAX_DECOMPRESSION_RATIO:
        raise LimitError(f"decompression ratio {total // size}:1 exceeds {MAX_DECOMPRESSION_RATIO}:1")
    return total


def sniff(path):
    """Media type from content, never from the filename."""
    with open(path, "rb") as f:
        head = f.read(4096)
    if head.startswith(b"%PDF"):
        return "application/pdf"
    if head.startswith(b"\x89PNG\r\n\x1a\n"):
        return "image/png"
    if head[:3] == b"\xff\xd8\xff":
        return "image/jpeg"
    if head[:6] in (b"GIF87a", b"GIF89a"):
        return "image/gif"
    if head[:2] == b"BM":
        return "image/bmp"
    if head[:4] in (b"II*\x00", b"MM\x00*"):
        return "image/tiff"
    if head[:4] == b"RIFF" and head[8:12] == b"WEBP":
        return "image/webp"
    if head.startswith(b"PK\x03\x04"):
        try:
            with zipfile.ZipFile(path) as z:
                names = set(z.namelist())
        except zipfile.BadZipFile:
            return "application/zip"
        if "xl/workbook.xml" in names:
            return "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
        if "word/document.xml" in names:
            return "application/vnd.openxmlformats-officedocument.wordprocessingml.document"
        return "application/zip"
    if head.startswith(_OLE):
        data = open(path, "rb").read(MAX_FILE_BYTES)
        if "__substg1.0_".encode("utf-16-le") in data or b"__properties_version1.0" in data:
            return "application/vnd.ms-outlook"
        return "application/vnd.ms-excel"
    low = head.lower().lstrip()
    if any(low.startswith(k) for k in _HEADER_KEYS):
        return "message/rfc822"
    if b"\x00" not in head:
        try:
            head.decode("utf-8")
            first = head.split(b"\n", 1)[0]
            return "text/csv" if first.count(b",") >= 1 else "text/plain"
        except UnicodeDecodeError:
            pass
    return "application/octet-stream"


def sha256_of(path):
    h = hashlib.sha256()
    with open(path, "rb") as f:
        for block in iter(lambda: f.read(1 << 20), b""):
            h.update(block)
    return h.hexdigest()


def inventory(path):
    size = os.path.getsize(path)
    if size > MAX_FILE_BYTES:
        raise LimitError(f"file is {size} bytes (limit {MAX_FILE_BYTES})")
    media = sniff(path)
    if media.startswith("application/vnd.openxml") or media == "application/zip":
        check_zip(path)
    return {
        "sha256": sha256_of(path),
        "size": size,
        "mediaType": media,
        "inspectors": INSPECTORS.get(media, []),
        "renderable": media in ("application/pdf", "application/vnd.openxmlformats-officedocument.wordprocessingml.document")
        or media.startswith("image/")
        or media.endswith(("spreadsheetml.sheet", "ms-excel")),
    }
