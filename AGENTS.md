# Smart Account POS & Enterprise Backend Architecture Manual

This document details the architectural standards, security primitives, caching strategies, and coding conventions implemented in this high-performance Laravel 13 REST API backend for the Point-of-Sale (POS), ERP, RBAC, and Cloud Drive system.

---

## 1. Architectural Philosophy & Layered Structure

The application adopts a **Clean / Layered Architecture** adhering strictly to **SOLID** principles and the **DRY (Don't Repeat Yourself)** methodology.

```
app/
├── Contracts/              # Interfaces enforcing loose coupling and testability
│   ├── AuthServiceInterface.php
│   ├── TokenServiceInterface.php
│   ├── EncryptionServiceInterface.php
│   ├── CacheServiceInterface.php
│   ├── BaseRepositoryInterface.php
│   ├── UserRepositoryInterface.php
│   ├── DriveItemRepositoryInterface.php
│   └── DriveServiceInterface.php
├── DTOs/                   # Strictly typed, immutable Data Transfer Objects
│   ├── Auth/
│   │   ├── RegisterDTO.php
│   │   ├── LoginDTO.php
│   │   └── TokenPayloadDTO.php
│   └── Drive/
│       ├── CreateFolderDTO.php
│       ├── UploadFileDTO.php
│       └── DriveQueryDTO.php
├── Repositories/           # Data access layer abstracting Eloquent queries
│   ├── BaseRepository.php  # Generic CRUD operations
│   ├── UserRepository.php
│   └── DriveItemRepository.php
├── Services/               # Core business logic decoupled from HTTP transport
│   ├── Security/
│   │   ├── EncryptionService.php # AES-256-GCM authenticated cipher
│   │   └── JwtTokenService.php   # HMAC-signed + AES-encrypted JWTs
│   ├── Cache/
│   │   └── CacheService.php      # L1 (Memory) + L2 (Redis) caching layer
│   ├── Auth/
│   │   └── AuthService.php
│   └── Drive/
│       └── DriveService.php      # At-rest file encryption, quotas & streaming
├── Http/
│   ├── Controllers/Api/v1/ # Lean, versioned API controllers
│   │   ├── BaseApiController.php
│   │   ├── AuthController.php
│   │   ├── PosController.php          # Core POS sales, hold sales, barcode, billing
│   │   ├── ErpModulesController.php   # Purchases, returns, marketers, transfers, ledgers
│   │   ├── DesignationController.php  # RBAC & permission matrix management
│   │   ├── DriveItemController.php    # Cloud Drive file & folder management
│   │   └── SystemHealthController.php
│   ├── Requests/           # Strict HTTP input validation (FormRequests)
│   ├── Resources/          # Clean JSON serialization layer
│   └── Middleware/         # Security & performance pipeline
│       ├── EnforceTlsAndSecurityHeaders.php
│       ├── JwtAuthenticate.php
│       ├── JwtOptionalAuthenticate.php
│       ├── CheckPermission.php
│       ├── ResponsePerformanceHeader.php
│       └── GzipCompression.php
├── Models/                 # Eloquent domain entities
│   ├── User.php
│   ├── Designation.php
│   ├── Permission.php
│   ├── Product.php
│   ├── Sale.php & SaleItem.php
│   ├── Customer.php, CustomerCategory.php & CustomerCollection.php
│   ├── Supplier.php & SupplierPayment.php
│   ├── Purchase.php, PurchaseItem.php, PurchaseReturn.php & PurchaseReturnItem.php
│   ├── SaleReturn.php & SaleReturnItem.php
│   ├── Marketer.php, MarketerSlab.php & MarketerPayment.php
│   ├── StockTransfer.php & StockTransferItem.php
│   ├── Wastage.php
│   ├── GeneralExpense.php
│   ├── FinancialAccount.php & AccountTransfer.php
│   ├── Outlet.php
│   └── DriveItem.php
└── Traits/
    └── ApiResponses.php    # Standardized API response schema
```

---

## 2. Standardized API Response Schema (`ApiResponses` Trait)

All endpoints output an identical, predictable JSON contract:

- **Success**:
  ```json
  {
    "success": true,
    "message": "Operation completed successfully.",
    "data": { ... },
    "meta": { ... }
  }
  ```
- **Error / Validation**:
  ```json
  {
    "success": false,
    "message": "The given data was invalid.",
    "error_code": "ERR_VALIDATION_FAILED",
    "errors": { "field": ["Error message details."] }
  }
  ```

---

## 3. Security, TLS & Cryptography

### Strict TLS & Defensive Headers (`EnforceTlsAndSecurityHeaders`)
- **HTTPS Enforcement**: Automatic 301 redirection to HTTPS in production environments.
- **HSTS (Strict-Transport-Security)**: `max-age=31536000; includeSubDomains; preload`.
- **Defensive Headers**:
  - `X-Content-Type-Options: nosniff` (prevents MIME sniffing).
  - `X-Frame-Options: DENY` (clickjacking mitigation).
  - `X-XSS-Protection: 0` (modern standard per OWASP).
  - `Referrer-Policy: strict-origin-when-cross-origin`.
  - `Content-Security-Policy: default-src 'self'; frame-ancestors 'none'; object-src 'none'`.
  - `Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=()`.

### Dual-Layer Encrypted JWT Authentication
- **Token Generation**: Claims (`sub`, `email`, `roles`) are encrypted into authenticated ciphertext using AES-256-GCM. Outer JWT envelope is cryptographically signed with HMAC-SHA256 (`JWT_SECRET`).
- **Sub-Millisecond Verification & Revocation**: Token unique IDs (`jti`) are checked against Redis cache blacklist in $O(1)$ time.
- **Single-Use Refresh Token Rotation**: Refresh tokens rotate upon each use.

### Role-Based Access Control (RBAC)
- Custom designations and granular permissions matrix.
- `CheckPermission` middleware verifies user capabilities for critical modules (`sales.create`, `inventory.manage`, `accounts.transfers`, etc.).

---

## 4. Redis and PHP Caching Strategies

- **L1 In-Memory Fast Cache**: Request-scoped memory cache with zero network overhead.
- **L2 Distributed Cache (Redis)**: High-throughput tag-based caching with atomic stampede locks (`withLock`) to prevent dog-piling under concurrent load.
- **Performance Profiling**: Middleware adds `X-Response-Time` and `X-Memory-Peak` headers.
- **Gzip Compression**: Compresses JSON payloads larger than 1KB by up to 80%.

---

## 5. API Endpoints Reference

All routes are prefixed with `/api/v1`:

### System & Authentication
| Method | Endpoint | Auth Required | Description |
|---|---|:---:|---|
| `GET` | `/health` | No | System health check (DB latency, cache, cipher status) |
| `POST` | `/auth/login` | No | Login with email/password credentials |
| `POST` | `/auth/refresh` | No | Rotate refresh token & obtain new access token |
| `GET` | `/auth/me` | **Yes** (Bearer) | Retrieve authenticated user profile |
| `POST` | `/auth/logout` | **Yes** (Bearer) | Revoke and blacklist current JWT token |
| `POST` | `/auth/register` | **Yes** (Bearer) | Register new user account |

### POS & Inventory Core
| Method | Endpoint | Auth Required | Description |
|---|---|:---:|---|
| `GET` | `/pos/bootstrap` | **Yes** (Bearer) | Initial POS state, products, categories, outlets |
| `GET` | `/pos/products` | **Yes** (Bearer) | Real-time product search with stock levels |
| `POST` | `/pos/sales` | **Yes** (Bearer) | Finalize sales transaction with items & payment |
| `GET` | `/pos/sales` | **Yes** (Bearer) | Sales transaction list with filters |
| `GET` | `/pos/sales/held` | **Yes** (Bearer) | List currently held sales |
| `DELETE` | `/pos/sales/held/{id}` | **Yes** (Bearer) | Resume or discard a held sale |
| `POST` | `/pos/collections` | **Yes** (Bearer) | Customer due collection payment entry |
| `GET` | `/pos/collections` | **Yes** (Bearer) | List customer collection receipts |
| `GET` | `/pos/dashboard` | **Yes** (Bearer) | Executive metrics, daily revenue, liquid cash |

### ERP & Extended Accounting Modules
| Method | Endpoint | Auth Required | Description |
|---|---|:---:|---|
| `GET` / `POST` | `/pos/purchases` | **Yes** (Bearer) | Inventory purchase invoices |
| `GET` / `POST` | `/pos/purchases/payments` | **Yes** (Bearer) | Supplier payments & settlements |
| `GET` / `POST` | `/pos/purchases/returns` | **Yes** (Bearer) | Supplier purchase return entries |
| `GET` / `POST` | `/pos/expenses` | **Yes** (Bearer) | General operating expenses |
| `GET` / `POST` | `/pos/accounts/transfers` | **Yes** (Bearer) | Inter-account balance transfers |
| `GET` / `POST` | `/pos/returns` | **Yes** (Bearer) | Customer sales returns & exchange |
| `GET` / `POST` | `/pos/marketers` | **Yes** (Bearer) | Sales reps & commission slab management |
| `POST` | `/pos/marketers/payments` | **Yes** (Bearer) | Marketer commission payment payouts |
| `GET` / `POST` | `/pos/transfers` | **Yes** (Bearer) | Inter-branch inventory transfers |
| `GET` / `POST` | `/pos/wastages` | **Yes** (Bearer) | Damaged/expired stock disposal |
| `POST` | `/pos/suppliers` | **Yes** (Bearer) | Create new supplier |
| `POST` | `/pos/customers` | **Yes** (Bearer) | Create new customer |
| `GET` | `/pos/customer-categories`| **Yes** (Bearer) | List customer pricing categories |
| `GET` | `/pos/reports` | **Yes** (Bearer) | Comprehensive reporting suite |

### Role-Based Access Control (RBAC)
| Method | Endpoint | Auth Required | Description |
|---|---|:---:|---|
| `GET` / `POST` | `/rbac/designations` | **Yes** (Bearer) | Manage staff designations / roles |
| `PUT` | `/rbac/designations/{id}/permissions` | **Yes** (Bearer) | Update permissions matrix for designation |
| `GET` | `/rbac/permissions` | **Yes** (Bearer) | List all system permission definitions |
| `GET` | `/rbac/users` | **Yes** (Bearer) | Staff user list with designations |
| `PUT` | `/rbac/users/{id}/designation` | **Yes** (Bearer) | Assign designation to user |

### Cloud Drive File Management
| Method | Endpoint | Auth Required | Description |
|---|---|:---:|---|
| `GET` | `/drive/summary` | **Yes** (Bearer) | Storage quota usage & limits |
| `GET` | `/drive/items` | **Yes** (Bearer) | List/search files and folders |
| `POST` | `/drive/folders` | **Yes** (Bearer) | Create folder |
| `POST` | `/drive/upload` | **Yes** (Bearer) | Upload file with optional AES-256 encryption |
| `GET` | `/drive/items/{uuid}` | **Yes** (Bearer) | Retrieve file/folder metadata |
| `GET` | `/drive/items/{uuid}/download` | **Yes** (Bearer) | Decrypt and stream file download |
| `PATCH` | `/drive/items/{uuid}/star` | **Yes** (Bearer) | Toggle starred status |
| `POST` | `/drive/items/{uuid}/trash` | **Yes** (Bearer) | Soft-delete item to trash |
| `POST` | `/drive/items/{uuid}/restore` | **Yes** (Bearer) | Restore item from trash |
| `DELETE` | `/drive/items/{uuid}` | **Yes** (Bearer) | Permanently delete file |

---

## 6. Quick Start & CLI Commands

```bash
# Install PHP dependencies
composer install

# Environment setup
cp .env.example .env
php artisan key:generate

# Run database migrations and seed demo data
php artisan migrate --seed

# Execute test suite
php artisan test

# Start API server (Port 8000)
php artisan serve
```
