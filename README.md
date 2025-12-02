# Flash-Sale-Checkout API

A high-concurrency API for selling limited-stock products during a flash sale.  
Supports **short-lived stock holds**, **checkout**, and an **idempotent payment webhook**.  
No frontend/UI included.

---

## 1. System Overview and Setup

### 1.1 Database Schema Overview

The system's integrity relies on four interconnected tables:

- **Products**  
- **Holds**  
- **Orders**  
- **payment_webhooks**  

### 1.2 Core Functionality

This API manages a multi-step, transactional checkout flow:

1. **Short-Lived Hold:**  
   Reserves a unit of stock for a limited time to allow the user to complete payment.

2. **Checkout:**  
   Finalizes the order and awaits payment confirmation.

3. **Idempotent Payment Webhook:**  
   Processes payment success/failure notifications and safely deducts stock without risk of double-deduction.

---

### 1.3 Assumptions and Invariants Enforced

The system uses a **pessimistic locking approach** combined with **high-speed caching** for availability checks to prevent overselling under high concurrency.

| Invariant | Mechanism | Impact |
|-----------|-----------|-------|
| **No Oversell** | `DB::transaction` immediately following `lockForUpdate()` on the Product row for stock checks and updates | Guarantees integrity of the reservation process under high contention |
| **Idempotency** | Insert into `payment_webhooks` with a unique index on `idempotency_key` as the first step of the webhook | Prevents double stock deduction if a payment gateway retries a webhook |
| **Deadlock Handling** | `while` loop with up to 5 retries and exponential backoff (`usleep`) to automatically re-attempt failed transactions | Improves system availability by resolving transient database deadlocks |
| **Stock State Guard** | Checks if the Order status is already Paid, Cancelled, or Failed (`isFinal()`) before deducting stock in the webhook | Prevents late webhooks from corrupting inventory |
| **Hold Cleanup** | Scheduled command (`holds:release-expired`) periodically marks expired reservations as `used=true` | Guarantees stock is released back to the public pool when the payment window closes |

---

## 2. File Structure and Purpose

The following table explains the purpose of the main application files and their role in enforcing concurrency safety and business logic:

| File Path | Purpose and Concurrency Role |
|-----------|-----------------------------|
| `app/Enums/OrderStatus.php` | Defines the finite states for an Order (PrePayment, Paid, Cancelled, etc.) |
| `app/Models/Product.php` | Core inventory model. Contains the `available_stock` accessor which respects active Holds |
| `app/Models/Hold.php` | Model for stock reservation. Used for locking and tracking expiration time |
| `app/Models/Order.php` | Model for the final sale, linking a Hold to the payment state |
| `app/Models/PaymentWebhook.php` | Model used exclusively to record the payment gateway's `idempotency_key` |
| `app/Http/Controllers/HoldController.php` | **CRITICAL:** Implements the `DB::transaction` with `lockForUpdate()` to safely reserve stock |
| `app/Http/Controllers/OrderController.php` | Converts a successful Hold into a PrePayment order state |
| `app/Http/Controllers/WebhookController.php` | **CRITICAL:** Implements idempotency check and final stock decrement upon payment success |
| `app/Console/Commands/ReleaseExpiredHolds.php` | Scheduled command that marks expired Holds as `used=true` to release stock |
| `routes/api.php` | Defines all public API routes for stock checks, holds, orders, and the webhook endpoint |

---

## 3. How to Run the App and Tests

**(Assumes standard Laravel/PHP environment setup.)**

### 3.1. Setup

| Step | Command | Description |
| :--- | :--- | :--- |
| **1. Clone** | `git clone [repository_url]` | Get the source code. |
| **2. Install** | `composer install` | Install PHP dependencies. |
| **3. Environment** | `cp .env.example .env` | Configure your database connection in the `.env` file. |
| **4. Database** | `php artisan migrate --seed` | Run migrations and seed the database. The seeder creates **Product with 50 units of stock** for testing. |
| **5. Scheduler** | `php artisan schedule:work` | Start the scheduler for background jobs (like hold cleanup). |

### 3.2. Start the Server

Start the local development server: php artisan serve


## 4. Where to See Logs/Metrics

All critical transactions, errors, and concurrency events are logged for operational monitoring.

### 4.1. Log Location

The primary log output is typically found in: storage/logs/laravel.log

### 4.2. Key Log Indicators to Monitor

* **`Deadlock encountered...`**: Indicates the automatic retry mechanism was triggered and succeeded or failed.
* **`Webhook Duplicate Ignored`**: Confirms the idempotency check successfully prevented a duplicate run.
* **`Webhook Payment Success - Stock Deducted`**: Confirms the final, permanent inventory change.
* **`Order already finalized, ignoring webhook.`**: Confirms the **Stock State Guard** invariant worked, preventing late updates.
* **`Expired hold released.`**: Confirms the scheduled job successfully returned reserved stock to the available pool.
