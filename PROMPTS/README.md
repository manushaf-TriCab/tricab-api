# Product assistant prompts

Prompts are immutable, versioned application resources. Runtime code must name a
specific Markdown file through `MarkdownPromptRepository`; it must not silently
select the newest version.

When prompt behaviour changes:

1. Copy the existing prompt to a new `-vN.md` file.
2. Update and test the new file.
3. Change the version requested by the container wiring.
4. Keep older versions while logs or stored responses still refer to them.

Current prompts:

- `product-assistant-query-v1.md` interprets natural-language cable requests
  into catalogue search terms. It never selects a product.
- `product-assistant-response-v1.md` is the original published-only response
  contract.
- `product-assistant-response-v2.md` writes permission-aware public or staff
  answers using only catalogue records authorized by the application.
