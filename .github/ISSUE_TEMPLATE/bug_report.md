---
name: Bug report
about: Create a report to help us improve the PHP SDK
title: "[BUG] "
labels: "bug"
assignees: ""
---

**SDK Version**
- Version: [e.g. 1.0.0]
- Composer Version: [e.g. 2.6.5]

**Describe the bug**
A clear and concise description of what the bug is.

**Code to Reproduce**
```php
// Add your PHP code here that demonstrates the issue
use Mcp\Server;

$server = new Server();
// ...rest of the code
```

**Steps to Reproduce**
1. Initialize the SDK with...
2. Call method X with parameters...
3. Try to access...
4. See error

**Expected behavior**
A clear and concise description of what you expected to happen.

**Actual behavior**
A clear and concise description of what actually happened, including any error messages, stack traces, or logs.

**Environment:**
- PHP Version: [e.g. 8.1.0]
- Operating System: [e.g. Ubuntu 22.04]
- Installation Method: [e.g. Composer, Manual]
- Framework (if applicable): [e.g. Laravel, Symfony]
- Transport Type: [HTTP/STDIO]

**Dependencies**
Output of `composer show | grep mcp`:
```
# Paste the output here
```

**Error Logs**
```
# If applicable, add relevant error logs
```

**Additional context**
- Are you using any specific MCP features (Tool Registration, Resource Chains, etc.)?
- Have you modified any default configurations?
- Any other relevant information about your setup
