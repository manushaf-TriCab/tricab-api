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
- `product-assistant-response-v3.md` is the bounded agent contract: tool choice,
  conversation corrections, one-question clarification and the current sizing /
  stock accuracy limits.
- `product-assistant-response-v4.md` adds the response-format contract: the
  Markdown subset the panel renders, when a list or table earns its place, and
  the trailing choice list the server turns into tappable buttons.
- `product-assistant-response-v5.md` routes a stated construction
  to `find_product` rather than `search_product_codes`, spells out that
  `search_product_codes` facets are exact code segments and not words
  (`jacketColour` wants `OR`, not "orange"), and confines the sizing caveat to
  answers that actually carry sizing.
- `product-assistant-response-v6.md` makes `list_style_options` the tool for a
  style-level question, tells the agent to search it by what a cable is *for* rather than only by
  style code, and requires it to read the new `pagination` / `coverage` fields before concluding
  that a style or a whole category does not exist.
- `product-assistant-response-v7.md` closes four gaps found testing v6 against the
  cable register: a quotation must come from a tool result received this turn (v6 let the agent
  reconstruct a plausible application sentence for one style out of a structurally equivalent
  style's wording); a record that does not carry the property asked about is reported as not
  stated rather than inferred from a neighbour; `SERVER_AUTHORIZED_REQUEST_CONTEXT` and the
  session's catalogue visibility are never narrated to the customer; and an answer stops once the
  question is answered, adding only a property the customer would otherwise wrongly assume. It also
  requires an application's qualifying condition to travel in the same sentence as the verdict.
- `product-assistant-response-v8.md` shortens replies so they do not repeat the product card.
- `product-assistant-response-v9.md` adds `offer_size_picker`, which shows the
  customer a core-count box and a size dropdown instead of asking for both in prose, and the style
  cards: a style the answer names as a bare `` `CODE` `` span is shown as a thumbnail card, so the
  agent writes only the styles it suggests that way. It also answers every question in a message
  (a requirement plus "is KA LSZH?" gets the no, checked with `list_style_options`, and the style
  that has the property), always uses the picker to ask for cores or size once the style is known,
  and keeps internal names (TSD, Cost API, tool names) out of customer replies. Stock status is
  live from the Cost API, so availability is "in stock" or "made to order" and never "not a
  catalogue line".
- `product-assistant-response-v10.md` is current. It keeps every v9 rule and changes only the
  voice: the agent speaks as TriCab's sales team ("we don't currently carry…", "our range", "our
  technical team") instead of an assistant reporting a search ("I couldn't find…"), ends on a real
  next step, and uses stock words ("in stock", "not in stock", "made to order") only when a tool
  result carries a stock status — an empty match is "we don't carry that", never "out of stock".
