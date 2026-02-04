# Admin Actor

## Purpose
Admin is responsible for managing the entire LMS system, including users, programs, content, payments, and system configuration.

## Capabilities
- Manage students, teachers, consultants, trainees
- Create and manage programs (online, offline)
- Approve registration and program requests
- View dashboards and statistics
- Manage payments, orders, transactions
- Configure system settings and notifications
- Manage content (banners, categories, skills, FAQs)

## Authentication
- Authenticated via JWT token
- Uses `api/login` and `api/refresh_token`

## Key Modules
- Accounts Management
- Program Management
- Orders & Transactions
- Dashboard & Statistics
- Notifications & Settings

## Related Routes
- `api/admin/accounts/*`
- `api/admin/programs/*`
- `api/admin/orders/*`
- `api/admin/dashboard/*`
- `api/admin/settings/*`

## Notes
- Admin has full access
- Admin actions often affect multiple actors (students, teachers)
