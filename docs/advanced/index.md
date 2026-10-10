# Advanced

Everything here is optional. A working server needs none of it.

* **[Events](events.md)** — PSR-14 events dispatched around requests, responses,
  errors and notifications. Useful for metrics, audit logs and debugging.
* **[Protocol extensions](extensions.md)** — opt-in extensions announced during
  capability negotiation, including MCP Apps (HTML UI resources).
* **[Custom message handlers](custom-handlers.md)** — taking over a JSON-RPC method the
  SDK knows, for example `tools/list`. A new method needs an
  [extension](extensions.md#writing-your-own-extension).
