# Security Review Checklist

Apply this checklist to every change that touches authentication, input parsing, or external I/O.

- **Input validation**: All external input is validated and normalized before use.
- **Injection**: Queries, shell commands, and templates use parameterization — never string concatenation.
- **AuthZ**: Every privileged action re-checks the caller's authorization server-side.
- **Secrets**: No credentials, tokens, or keys are logged or committed.
- **Output encoding**: Data rendered into HTML, URLs, or headers is contextually encoded.
- **Dependencies**: New dependencies are pinned and free of known advisories.
