# Smart Dube

A Digital Buy Now, Pay Later (BNPL) & Ledger System tailored specifically for local neighborhood merchants in Ethiopia.

## Project Overview

Smart Dube is designed to digitize "Dube"—the traditional, trust-based, and manual paper-ledger credit system used by local shops to allow regular customers to buy goods on credit.

By transitioning manual, error-prone paper ledgers into a fast and reliable digital framework, the platform resolves key operational pain points for merchants, such as unrecorded debts, default risks, accounting calculation errors, and delays in debt collection.

## Technology Stack

*   **Frontend**: React (Single Page Application built with Vite), React Router, and Tailwind CSS for a modern, responsive, and glassmorphic UI. Icons are provided by Lucide React.
*   **Backend**: Node.js + Express.js providing a robust RESTful API.
*   **Security & Authentication**: JSON Web Tokens (JWT) for secure session management, `bcryptjs` for password hashing, and a 6-digit SMS OTP flow for password resets.
*   **Database**: A custom, portable JSON-backed relational datastore built to simulate SQL environments, ensuring immediate plug-and-play execution without complex native SQLite/PostgreSQL build dependencies during evaluation.

## Core Roles & Features

The system is structured around three distinct, secure user roles:

### 1. System Admin
*   **Merchant KYC Verification**: Admins review and approve newly registered merchants to ensure they have valid business licenses.
*   **System Monitoring**: Real-time view of total Dube volume, registered merchants, and active pending reviews.
*   **Audit & Gateway Logs**: Immutable audit trails for all system actions and simulated webhooks for payment gateways (Telebirr, Chapa).

### 2. Merchant (Shop Owner)
*   **Customer Credit Profiling**: Merchants can register their trusted customers (using their Fayda National ID) and assign them a specific credit limit.
*   **Log Dube Sales**: A point-of-sale style interface to log new credit transactions, calculate totals, and set repayment due dates.
*   **Debt Tracking & Notifications**: Monitor active balances and simulate sending SMS reminders to customers with overdue balances.

### 3. Customer
*   **Personal Dashboard**: Customers can log in to see their total active credit limit, current outstanding balance, and available remaining credit.
*   **Transaction History**: A transparent ledger showing all past purchases made on credit.
*   **Digital Repayment**: Simulated integrations with local digital payment gateways (Telebirr, Chapa, CBE Birr) allowing customers to easily settle their Dube debts online.

## How to Run Locally

### 1. Start the Backend API
Navigate to the `server` directory, install dependencies, and start the Node.js server.
```bash
cd server
npm install
node src/server.js
```
The backend API will run on `http://localhost:5000`. 
*Note: A `smart_dube_data.json` file will automatically be generated in the root directory to persist your data.*

### 2. Start the Frontend Client
Open a new terminal window, navigate to the `client` directory, install dependencies, and start the Vite development server.
```bash
cd client
npm install
npm run dev
```
The frontend application will be accessible at `http://localhost:3000`.

### 3. Demo Accounts
The login screen features a "Quick Evaluator Demo Logins" section that allows you to instantly switch between Admin, Merchant, and Customer roles for easy evaluation. You can also register new accounts using the registration tab.
