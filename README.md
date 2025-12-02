##  Flash Sale Checkout API Readme 

This document outlines the architecture, setup, and key invariants of a high-concurrency API designed to safely sell a limited-stock product during a flash sale.

---

## 1. System Overview and Setup

### 1.1. Core Functionality

This API manages a multi-step, transactional checkout flow:

1.  **Short-Lived Hold:** Reserves a unit of stock for a limited time to allow the user to complete payment.
2.  **Checkout:** Finalizes the order and awaits payment confirmation.
3.  **Idempotent Payment Webhook:** Processes payment success/failure notifications and safely deducts stock without risk of double-deduction.

### 1.2. Assumptions and Invariants Enforced

The system uses a pessimistic locking approach combined with high-speed caching for availability checks to prevent overselling under high concurrency.

| Invariant | Mechanism | Impact |
| :--- | :--- | :--- |
| **No Oversell** | `DB::transaction` immediately following `lockForUpdate()` on the **Product** row for stock checks and updates. | Guaranteed integrity of the reservation process under high contention. |
| **Idempotency** | Insert into `payment_webhooks` with a **unique index on `idempotency_key`** as the first step of the webhook. | Prevents double stock deduction if a payment gateway retries a webhook. |
| **Deadlock Handling** | **`while` loop with up to 5 retries** and exponential backoff (`usleep`) to automatically re-attempt failed transactions. | Improves system availability by resolving transient database deadlocks. |
| **Stock State Guard** | Checks if the **Order status is already Paid, Cancelled, or Failed** (`isFinal()`) before deducting stock in the webhook. | Prevents late webhooks from corrupting inventory. |
| **Hold Cleanup** | Scheduled command (`holds:release-expired`) periodically marks expired reservations as `used=true`. | Guarantees stock is released back to the public pool when the payment window closes. |

---

## 2. How to Run the App and Tests

**(Assumes standard Laravel/PHP environment setup.)**

### 2.1. Setup

| Step | Command | Description |
| :--- | :--- | :--- |
| **1. Clone** | `git clone [repository_url]` | Get the source code. |
| **2. Install** | `composer install` | Install PHP dependencies. |
| **3. Environment** | `cp .env.example .env` | Configure your database connection in the `.env` file. |
| **4. Database** | `php artisan migrate --seed` | Run migrations and seed the database. The seeder creates **Product ID 1 with 10 units of stock** for testing. |
| **5. Scheduler** | `php artisan schedule:work` | Start the scheduler for background jobs (like hold cleanup). |

### 2.2. Start the Server

Start the local development server: php artisan serve


## 3. Where to See Logs/Metrics

All critical transactions, errors, and concurrency events are logged for operational monitoring.

### 3.1. Log Location

The primary log output is typically found in: storage/logs/laravel.log

### 3.2. Key Log Indicators to Monitor

* **`Deadlock encountered...`**: Indicates the automatic retry mechanism was triggered and succeeded or failed.
* **`Webhook Duplicate Ignored`**: Confirms the idempotency check successfully prevented a duplicate run.
* **`Webhook Payment Success - Stock Deducted`**: Confirms the final, permanent inventory change.
* **`Order already finalized, ignoring webhook.`**: Confirms the **Stock State Guard** invariant worked, preventing late updates.
* **`Expired hold released.`**: Confirms the scheduled job successfully returned reserved stock to the available pool.
