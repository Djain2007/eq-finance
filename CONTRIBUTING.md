# Contributing to EQ Finance

Thank you for your interest in contributing to **EQ Finance**!

## Code of Conduct
Please be polite, respectful, and constructive in all discussions and pull requests.

## Development Setup

1. Fork and clone the repository:
   ```bash
   git clone https://github.com/Djain2007/eq-finance.git
   cd eq-finance
   ```
2. Start the local stack with Docker:
   ```bash
   docker compose up -d
   ```
   Or use the local PHP server:
   ```bash
   cp config/config.example.php config/config.php
   php -S 127.0.0.1:8080 -t . tests/router.php
   ```

## Running Tests

Run the test suite before submitting any changes:
```bash
php tests/run-tests.php
```

Ensure that:
- All PHP code uses `declare(strict_types=1);`.
- No user input is directly concatenated into SQL queries (use PDO prepared statements).
- SSRF protections in `app/Services/SymbolValidator.php` remain strict.
- New features include corresponding test assertions.

## Submitting Pull Requests

1. Create a feature branch: `git checkout -b feature/your-feature-name`.
2. Commit changes with clear, descriptive commit messages.
3. Push to your branch and open a Pull Request.

