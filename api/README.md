# Authentication API Documentation

## Overview
The SmartTutor authentication system provides secure user authentication with support for two-factor authentication, refresh tokens, and comprehensive security monitoring.

## Base URL
```
/api/auth.php
```

## Tutor Directory Endpoint

### 1. Fetch Tutors
```http
GET /api/tutors.php
```

#### Query Parameters
- `q` or `search` *(optional)* — free-text query matched against tutor names, bios, and subjects.
- `subject` *(optional)* — filter tutors that teach a particular subject (partial matches supported).
- `mode` *(optional)* — filter by delivery mode stored in availability metadata (e.g. `online`, `in-person`).
- `page` *(optional)* — page number for pagination (default `1`).
- `limit` *(optional)* — page size, capped at `50` (default `12`).

#### Response
```json
{
    "status": "ok",
    "message": "Tutors retrieved successfully.",
    "data": [
        {
            "id": 12,
            "name": "Jane Doe",
            "subjects": ["Mathematics", "Physics"],
            "rating": 4.9,
            "total_reviews": 18,
            "price": 25,
            "photo": "/public/images/tutor-placeholder.svg",
            "bio": "STEM specialist with 8 years of experience…",
            "location": "Remote",
            "availability": ["Mon", "Wed", "Sat"],
            "mode": ["online", "in-person"],
            "highlights": [
                "Specialises in Mathematics, Physics",
                "8+ years teaching experience"
            ],
            "status": "active"
        }
    ],
    "total": 42,
    "pagination": {
        "page": 1,
        "per_page": 12
    }
}
```

If the filters match no tutors the API returns an empty `data` array with a helpful message. Database connectivity errors are surfaced with `status: "error"` and include diagnostics under the `meta` key.

## Authentication Endpoints

### 1. User Registration
```http
POST /api/auth.php?action=register
Content-Type: application/json

{
    "email": "user@example.com",
    "password": "SecurePassword123!",
    "name": "John Doe",
    "role": "student"
}
```

**Response**
```json
{
    "status": "ok",
    "message": "Registration successful",
    "token": "eyJhbG...",
    "user": {
        "id": 123,
        "email": "user@example.com",
        "name": "John Doe",
        "role": "student"
    }
}
```

### 2. User Login
```http
POST /api/auth.php?action=login
Content-Type: application/json

{
    "email": "user@example.com",
    "password": "SecurePassword123!"
}
```

**Response with 2FA Disabled**
```json
{
    "status": "ok",
    "message": "Login successful",
    "token": "eyJhbG...",
    "refresh_token": "abc123...",
    "user": {
        "id": 123,
        "email": "user@example.com",
        "name": "John Doe",
        "role": "student"
    }
}
```

**Response with 2FA Enabled**
```json
{
    "status": "2fa_required",
    "message": "2FA code required",
    "temp_token": "temp_xyz..."
}
```

### 3. Two-Factor Authentication

#### Enable 2FA
```http
POST /api/auth.php?action=enable2fa
Authorization: Bearer <token>
```

**Response**
```json
{
    "status": "ok",
    "secret": "JBSWY3DPEHPK3PXP",
    "qr_code": "otpauth://totp/...",
    "backup_codes": [
        "ABCD-EFGH",
        "IJKL-MNOP",
        ...
    ]
}
```

#### Verify 2FA Code
```http
POST /api/auth.php?action=verify2fa
Content-Type: application/json

{
    "temp_token": "temp_xyz...",
    "code": "123456"
}
```

**Response**
```json
{
    "status": "ok",
    "token": "eyJhbG...",
    "refresh_token": "abc123..."
}
```

### 4. Token Management

#### Refresh Access Token
```http
POST /api/auth.php?action=refresh
Content-Type: application/json

{
    "refresh_token": "abc123..."
}
```

**Response**
```json
{
    "status": "ok",
    "token": "eyJhbG...",
    "refresh_token": "new_abc123..."
}
```

#### Logout
```http
POST /api/auth.php?action=logout
Authorization: Bearer <token>
Content-Type: application/json

{
    "refresh_token": "abc123..."
}
```

**Response**
```json
{
    "status": "ok",
    "message": "Logged out successfully"
}
```

## Security Headers

All API responses include the following security headers:
```http
Content-Type: application/json; charset=utf-8
Access-Control-Allow-Origin: *
Access-Control-Allow-Methods: GET, POST, OPTIONS
Access-Control-Allow-Headers: Content-Type, Authorization
```

## Rate Limiting

The API implements rate limiting with the following rules:
- 5 requests per minute per IP address
- 5 failed login attempts before account lockout
- 15-minute lockout duration

Rate limit headers are included in responses:
```http
X-RateLimit-Limit: 5
X-RateLimit-Remaining: 4
X-RateLimit-Reset: 1635789600
```

## Error Responses

### 400 Bad Request
```json
{
    "error": "Invalid JSON payload"
}
```

### 401 Unauthorized
```json
{
    "error": "Invalid credentials"
}
```

### 422 Unprocessable Entity
```json
{
    "error": "Missing required fields",
    "fields": ["email", "password"]
}
```

### 429 Too Many Requests
```json
{
    "error": "Too many requests",
    "retry_after": 60
}
```

## Password Requirements

Passwords must meet the following criteria:
- Minimum 12 characters
- At least one uppercase letter
- At least one lowercase letter
- At least one number
- At least one special character

## Security Best Practices

1. Always use HTTPS
2. Store tokens securely (HttpOnly cookies recommended)
3. Implement refresh token rotation
4. Monitor for suspicious activities
5. Enable 2FA for sensitive accounts
6. Regular security audits