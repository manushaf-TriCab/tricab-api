"""Generated fixtures for the Quotegen inspector tests. No customer files."""
import io
from email.message import EmailMessage

from PIL import Image


def png_bytes(color=(200, 30, 30), size=(8, 6)):
    buf = io.BytesIO()
    Image.new("RGB", size, color).save(buf, "PNG")
    return buf.getvalue()


def build_eml(path):
    """multipart/related (html + CID image) inside mixed, with a PDF-ish attachment and a nested message."""
    inner = EmailMessage()
    inner["Subject"] = "Inner forwarded"
    inner["From"] = "a@x.test"
    inner["To"] = "b@x.test"
    inner.set_content("inner body: 500m 4C 2.5mm")
    m = EmailMessage()
    m["Subject"] = "RFQ test"
    m["From"] = "Buyer <buyer@cust.test>"
    m["To"] = "sales@tricab.test"
    m["Date"] = "Mon, 26 Oct 2026 09:00:00 +1100"
    m["Message-ID"] = "<abc@cust.test>"
    m.set_content("plain version: see table")
    m.add_alternative(
        "<html><body><p>Please quote</p><table><tr><th>Item</th><th>Qty</th></tr>"
        "<tr><td>4C 2.5mm</td><td>500</td></tr></table><img src='cid:logo1'></body></html>", subtype="html")
    m.get_payload()[1].add_related(png_bytes(), "image", "png", cid="<logo1>", filename="logo.png")
    m.add_attachment(b"%PDF-1.4 fake", maintype="application", subtype="pdf", filename="spec.pdf")
    m.add_attachment(inner)
    path.write_bytes(bytes(m))
    return path


def build_html_only_eml(path):
    m = EmailMessage()
    m["Subject"] = "HTML only"
    m["From"] = "a@x.test"
    m["To"] = "b@x.test"
    m.set_content("<html><body><table><tr><td>Cable</td><td>Metres</td></tr><tr><td>XLPE 3C 16mm</td><td>1200</td></tr></table></body></html>",
                  subtype="html")
    path.write_bytes(bytes(m))
    return path


def build_xlsx(path):
    from openpyxl import Workbook
    from openpyxl.comments import Comment
    from openpyxl.styles import Font, PatternFill
    wb = Workbook()
    ws = wb.active
    ws.title = "Schedule"
    ws.merge_cells("A1:C1")
    ws["A1"] = "Cable Schedule"
    ws["A2"], ws["B2"], ws["C2"] = "Item", "Qty", "Total"
    ws["A3"], ws["B3"] = "4C 2.5mm", 10
    ws["C3"] = "=B3*2"
    ws["B3"].fill = PatternFill("solid", fgColor="FFFF00")
    ws["A3"].font = Font(color="FF0000", bold=True)
    ws["A3"].comment = Comment("check size", "buyer")
    ws.row_dimensions[4].hidden = True
    ws["A4"] = "hidden row"
    ws.column_dimensions["E"].hidden = True
    ws["E1"] = "hidden col"
    h = wb.create_sheet("Secret")
    h.sheet_state = "hidden"
    h["A1"] = "hidden sheet data"
    vh = wb.create_sheet("VeryHidden")
    vh.sheet_state = "veryHidden"
    vh["A1"] = "x"
    wb.save(path)
    return path


def build_pdf(path):
    """One page with text and a ruled 2x2 table, written by hand (no extra deps)."""
    content = (
        "BT /F1 12 Tf 72 720 Td (Cable quote request 500m) Tj ET\n"
        "72 600 200 60 re S 72 630 m 272 630 l S 172 600 m 172 660 l S\n"
        "BT /F1 10 Tf 80 640 Td (Item) Tj ET BT /F1 10 Tf 180 640 Td (Qty) Tj ET\n"
        "BT /F1 10 Tf 80 610 Td (CU4) Tj ET BT /F1 10 Tf 180 610 Td (500) Tj ET\n")
    objs = ["<< /Type /Catalog /Pages 2 0 R >>", "<< /Type /Pages /Kids [3 0 R] /Count 1 >>",
            "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>",
            f"<< /Length {len(content)} >>\nstream\n{content}endstream", "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>"]
    out, offs = b"%PDF-1.4\n", []
    for i, o in enumerate(objs, 1):
        offs.append(len(out))
        out += f"{i} 0 obj\n{o}\nendobj\n".encode()
    x = len(out)
    out += f"xref\n0 {len(objs) + 1}\n0000000000 65535 f \n".encode()
    out += "".join(f"{o:010d} 00000 n \n" for o in offs).encode()
    out += f"trailer\n<< /Size {len(objs) + 1} /Root 1 0 R >>\nstartxref\n{x}\n%%EOF\n".encode()
    path.write_bytes(out)
    return path


def build_blank_pdf(path):
    path.write_bytes(build_pdf(path).read_bytes().replace(b"Cable quote request 500m", b""))
    return path


def build_docx(path):
    import docx
    d = docx.Document()
    d.add_heading("Quote request", 1)
    d.add_paragraph("Supply 300m of 3C 4mm cable.")
    t = d.add_table(rows=2, cols=2)
    t.rows[0].cells[0].text, t.rows[0].cells[1].text = "Item", "Qty"
    t.rows[1].cells[0].text, t.rows[1].cells[1].text = "3C 4mm", "300"
    img = path.parent / "pic.png"
    img.write_bytes(png_bytes())
    d.add_picture(str(img))
    d.save(path)
    return path
