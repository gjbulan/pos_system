# Release Readiness Checklist

## Code

- [ ] No debug `echo`, `var_dump`, or `print_r`
- [ ] No test passwords hardcoded in production files
- [ ] Database credentials are configured correctly
- [ ] `.env.example` is updated
- [ ] File paths work inside XAMPP `htdocs`

## Database

- [ ] Latest schema imports cleanly
- [ ] Test data is optional and separated
- [ ] Admin user can be created
- [ ] Required indexes exist
- [ ] Branch IDs are respected

## Security

- [ ] Login required for protected pages
- [ ] Role checks work
- [ ] Permission checks work
- [ ] Backup/restore is protected
- [ ] Upload validation exists
- [ ] SQL uses prepared statements where applicable

## UI

- [ ] Desktop dashboard looks correct
- [ ] Tablet layout is usable
- [ ] Mobile layout is usable
- [ ] Receipt prints cleanly
- [ ] Tables do not overflow badly

## Business Flow

- [ ] Products can be created
- [ ] Stock can be added
- [ ] Sales can be completed
- [ ] Stock decreases after sale
- [ ] Sale can be viewed
- [ ] Receipt can be printed
- [ ] Reports show sale data
