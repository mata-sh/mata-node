# Agent Notes

## Changelog

Keep `CHANGELOG.md` current without being asked, following
[CONTRIBUTING.md](CONTRIBUTING.md#release--changelog).

## Commit messages

Keep them concise. The subject (`type(scope): summary`) says what changed; add a body only
when the subject is not enough, and then state what was done in a sentence or two. Skip
background, motivation and anything the diff already shows.

Good: `Remove mail.config.env, the MailConfig DTO and the symfony/mailer dependency along
with references in Docker, Makefile and docs.`
Too much: `The node never sends mail; alerting belongs to the dashboard. Drop ...`
