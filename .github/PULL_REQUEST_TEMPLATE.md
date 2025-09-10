### Pull Request Guidelines

Pull Requests should follow PSR standards and maintain a clean, focused scope. Each PR should ideally consist of atomic commits following this structure:

```
Mcp/Component: brief description of change

[Detailed description of changes and their impact]

Breaking Changes: yes/no
Related: #issue_number
Fixes: #issue_number
```

#### Commit Message Guidelines

The first line must follow this format:

- `Mcp/Component`: Namespace or component being modified (e.g., `Mcp/Server`, `Mcp/Capability/Tool`)
- After the colon: Brief description in present tense
- Keep it under 72 characters
- No trailing period
- Must be clear and descriptive

Examples:
```
Mcp/Server/Transport: implement HTTP transport layer
Mcp/Capability/Tool: add async handler support
all: upgrade minimum PHP version to 8.1
```

#### Quality Requirements

1. Code Standards
   - Must follow [PSR-12](https://www.php-fig.org/psr/psr-12/)
   - Must pass PHP CS Fixer (`composer run cs`)
   - Must pass PHPStan (`composer run phpstan`)
   - Must pass all tests (`composer run tests`)

2. Documentation
   - PHPDoc blocks for all public methods
   - README updates if needed
   - Changelog entry in appropriate section

3. Testing
   - New tests for new features
   - Updated tests for bug fixes
   - No breaking changes to existing tests

#### Required Checklist

- [ ] Followed PSR-12 standards
- [ ] Added/updated PHPDoc blocks
- [ ] Added/updated tests
- [ ] All tests passing
- [ ] CS Fixer checks passing
- [ ] PHPStan checks passing
- [ ] Changelog updated
- [ ] BC breaks documented (if any)

Your PR description should explain:
1. What problem this solves
2. How it solves the problem
3. Any breaking changes
4. Upgrade notes (if needed)
