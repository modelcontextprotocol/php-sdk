# Servers

An MCP server exposes four kinds of elements to a connected client. They differ by who
decides to use them:

* A **[tool](tools.md)** is an action the *model* picks and calls. This is the page most
  people want first.
* A **[resource](resources.md)** is read-only data the *application* chooses to read,
  addressed by a fixed URI. **[Resource templates](resource-templates.md)** are the same
  thing with variables in the URI, for data that is generated per request.
* A **[prompt](prompts.md)** is a message template a *person* invokes by name, from a
  menu or a slash command.

Around those, the rest of what a server declares:

* **[Completions](completions.md)** is server-side autocomplete for prompt and
  resource-template arguments.
* **[Schema generation](schemas.md)** explains how your PHP types and docblocks become
  the JSON Schema a model sees, and how to override it where the types are not enough.
* **[Registering elements](registration.md)** covers the three ways an element reaches
  the registry: attribute discovery, explicit registration, or both at once.

Every page here stands on its own; jump straight to the one you need. If you have not
built a server yet, start with **[First server](../get-started/first-server.md)**
instead.

What happens *inside* the functions you register — logging, progress, asking the client
for an LLM completion — is the next section,
**[Inside your handler](../handlers/index.md)**. Getting the server in front of a client
is **[Running your server](../run/index.md)**.
