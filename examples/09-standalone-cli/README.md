# Standalone example app with CLI

This is just for testing and debugging purposes. Different from the other examples, this one does not use the same
autoloader, but installs the SDK via path repository and therefore has mostly decoupled dependencies.

Install dependencies:

```bash
cd /path/to/your/project/examples/09-standalone-cli
composer update
```

Run the CLI with:

```bash
DEBUG=1 php index.php
```

You will see debug outputs to help you understand what is happening.

In this terminal you can now test by adding some JSON strings. See `example-requests.json`.

Run with Inspector:

```bash
npx @modelcontextprotocol/inspector php index.php
```
