# Agent Notes

## Changelog

Keep `CHANGELOG.md` current without being asked, following
[CONTRIBUTING.md](CONTRIBUTING.md#release--changelog).

## Secret scanning

gitleaks is not in CI; `gitleaks-action` needs a paid licence for org repos. Run
`gitleaks git` locally before publishing and after any history rewrite.

## Commit messages

Keep them concise. The subject (`type(scope): summary`) says what changed; add a body only
when the subject is not enough, and then state what was done in a sentence or two. Skip
background, motivation and anything the diff already shows.

Good: `Remove mail.config.env, the MailConfig DTO and the symfony/mailer dependency along
with references in Docker, Makefile and docs.`
Too much: `The node never sends mail; alerting belongs to the dashboard. Drop ...`
