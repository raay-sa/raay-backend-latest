# Flow: Student Purchases a Program

## Actors Involved
- Student
- Payment Gateway (Moyasar)
- System

## Preconditions
- Student is registered and logged in
- Program exists and is active
- Program is purchasable

## Flow Steps
1. Student views program details
2. Student adds program to cart
3. System checks purchase eligibility
4. Student initiates purchase
5. Payment gateway processes payment
6. Payment webhook updates transaction status
7. Student subscription is activated

## API Calls Involved
1. `GET api/student/cart/can-purchase/{programId}`
2. `POST api/student/cart`
3. `POST api/student/cart/{cartId}/purchase`
4. `POST api/moyasar/status`
5. `POST api/moyasar/webhook`

## Success Outcome
- Program added to student's subscriptions
- Transaction marked as successful
- Invoice available for download

## Failure Scenarios
- Payment failure
- Duplicate purchase attempt
- Gateway timeout

## Related Tables
- users
- programs
- carts
- transactions
- subscriptions
