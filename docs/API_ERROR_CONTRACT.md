# API Error Contract

The API keeps successful legacy payloads compatible while standardizing framework-level errors.

## Error envelope

```json
{
  "success": false,
  "message": "Human-readable message",
  "error": "Human-readable message",
  "code": "VALIDATION_ERROR",
  "request_id": "f2b582c7-8c86-4b38-aabe-d8df18b0efba"
}
```

Validation errors additionally contain an `errors` object keyed by input field.

## Standard machine codes

| HTTP | Code |
| --- | --- |
| 400 | `BAD_REQUEST` |
| 401 | `UNAUTHENTICATED` |
| 403 | `FORBIDDEN` |
| 404 | `NOT_FOUND` |
| 405 | `METHOD_NOT_ALLOWED` |
| 409 | `CONFLICT` |
| 422 | `VALIDATION_ERROR` or `UNPROCESSABLE_ENTITY` |
| 429 | `RATE_LIMITED` |
| 500+ | `INTERNAL_ERROR` |

## Request correlation

Every HTTP response receives an `X-Request-ID` header. Frontends should include this value when reporting an unexpected API failure.

A frontend may provide `X-Request-ID` itself when it follows the accepted safe format. Otherwise the API generates a UUID.

Never display internal exception details to end users in production.
