# taypme/destroy

`taypme/destroy` is a destructive Symfony 8 console app that rewrites every repository for a given GitHub vendor into a single-commit target branch and removes every other branch.

## What it does

The `destroy` command:

1. Fetches all repositories for a GitHub user or organization.
2. Prints the repository list.
3. Asks for confirmation.
4. For each repository:
   - clones it into `./tmp/<repo>`
   - creates a single orphan commit on a temporary branch
   - moves that commit onto the target branch
   - creates a single commit with message `---`
   - dates that commit to `June 9, 1992 9:30 AM`
   - force-pushes the target branch
   - sets the target branch as the default branch
   - deletes every other branch on the repository
   - removes the temporary clone from `./tmp`

This is destructive by design.

## Requirements

- PHP 8.4+
- Composer
- Git
- GitHub CLI (`gh`)
- Authenticated access to the target GitHub account or organization

## Install

```bash
composer install
```

## Usage

```bash
bin/console destroy <vendor> [--from=master] [--to=main]
```

Example:

```bash
bin/console destroy taypme
```

The command will show the repositories it found and ask for confirmation before making any changes.

## Notes

- The command clones repositories into `./tmp`, which is ignored by Git.
- Temporary clones are deleted after each repository is processed.
- `gh` is used for repository discovery and branch metadata changes.
- `--from` defaults to `master`.
- `--to` defaults to `main`.
- The command works even if a repository is already on the target branch.
- The command leaves only the target branch on each repository.

## Warning

Running this command will permanently rewrite repository history and remove every branch except the target branch. Only use it if you are certain that is what you want.

## Safety

> [!WARNING]
> This command is destructive. It force-pushes a rewritten branch, changes the default branch, and deletes every other branch in each repository. Run it only against repositories you are prepared to permanently modify.
