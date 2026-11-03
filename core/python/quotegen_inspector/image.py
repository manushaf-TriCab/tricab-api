"""Image inspection and downscaling for model vision input."""
import io

from PIL import Image

from .limits import MAX_RENDER_PIXELS

Image.MAX_IMAGE_PIXELS = 100_000_000  # decompression-bomb guard


def image_info(path):
    with Image.open(path) as im:
        return {"format": im.format, "width": im.width, "height": im.height, "mode": im.mode,
                "frames": getattr(im, "n_frames", 1), "truncated": False, "next_cursor": None, "unreadable": []}


def image_png(path, max_side=1568):
    max_side = max(16, min(int(max_side), MAX_RENDER_PIXELS))
    with Image.open(path) as im:
        im.load()
        orig = (im.width, im.height)
        im = im.convert("RGBA" if im.mode in ("RGBA", "LA", "P") else "RGB")
        im.thumbnail((max_side, max_side))
        buf = io.BytesIO()
        im.save(buf, "PNG")
    return buf.getvalue(), {"width": im.width, "height": im.height, "originalWidth": orig[0], "originalHeight": orig[1]}
