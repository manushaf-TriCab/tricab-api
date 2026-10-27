import pytest

from cable_routes.parse import conductor_prefix, parse_item


@pytest.mark.parametrize("item,code,construction,colour,ok", [
    ("KA9X-PASN/5C16BK", "KA", "5C16", "BK", True),
    ("KA9X-PASN/5C16", "KA", "5C16", "", True),
    ("XX1X-PASN/1C300BK", "XX", "1C300", "BK", True),
    ("LA21X-XXXA/3C2.5", "LA", "3C2.5", "", True),
    ("la21x-xxxa/3c2.5bk", "LA", "3C2.5", "BK", True),
    ("XDT1-ABC/2C1.5RE", "XDT", "2C1.5", "RE", True),
    ("KDT1-ABC/1C25", "KDT", "1C25", "", True),
    ("CDT9/4C4WH", "CDT", "4C4", "WH", True),
    ("KA9X-PASN/5C16BK-TSD", "KA", "other", "", False),   # suffix after the colour
    ("KA9X-PASN", "KA", "other", "", False),              # no slash
    ("KA9X/PASN/5C16", "KA", "other", "", False),         # a second slash
    ("KA9X-PASN/BLACK", "KA", "other", "", False),
    ("KA9X-PASN/5C16BK1", "KA", "other", "", False),
])
def test_parse_item(item, code, construction, colour, ok):
    p = parse_item(item)
    assert (p.code, p.construction, p.colour, p.parse_ok) == (code, construction, colour, ok)


@pytest.mark.parametrize("item,expected", [
    ("NX-1R8-NEX", True), ("dx-1C16", True), ("MNX-5", True), ("N1-300", True), ("AL-1", True),
    ("RS0002", False), ("NXY-1", False), ("MB_BLACK", False),
])
def test_conductor_prefix(item, expected):
    assert conductor_prefix(item) is expected
