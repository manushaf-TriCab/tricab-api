"""TriCab Product Finder.

Joins the two halves of TriCab's existing cable logic:

  * style selection and product-code assembly, from the RFQ parser
  * conductor sizing, from the legacy Cable Calculator

Neither tool can answer a customer on its own. The calculator sizes a conductor
but cannot name a sellable product; the parser names one but cannot size it.
"""

__version__ = "0.1.0"
