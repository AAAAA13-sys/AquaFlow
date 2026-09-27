# AquaFlow

## ARIMA-Based Demand Forecasting and Inventory Management System for Water Refilling Station Operations

---

## Current State of the Project

AquaFlow is currently a **front-end prototype**. Every screen is built and interactive,
but the application runs entirely in the browser with **demo data stored in
`localStorage`**. There is no server, no database connection, and no real forecasting
yet.
---
## Project Status

| Component / Layer | Status |
|-------------------|--------|
| Frontend UI & design system (Tier 1) | Complete |
| Client-side application logic | Complete |
| MySQL database schema (Tier 4) | Drafted |
| Backend server & REST API (Tier 2) | Not started |
| Database integration | Not started |
| ARIMA forecasting engine (Tier 3) | Not started |
| Dynamic Safety Stock & ROP engine | Partial |
| Authentication & RBAC | Prototype only |
| Production queue advancement | Not started |
| Automated testing & system evaluation | Not started |
| Production deployment | Not started |

---

## Project Structure

```
Water Station/
├── index.html                  
├── admin-login.html            
├── css/
│   └── styles.css              
├── js/
│   ├── data/
│   │   ├── initial-data.js     
│   │   └── store.js            
│   ├── auth/
│   │   └── auth.js             
│   ├── pos/
│   │   ├── cart.js             
│   │   ├── custody.js          
│   │   └── checkout.js         
│   └── admin/
│       ├── navigation.js       
│       ├── inventory.js        
│       ├── arima.js            
│       ├── insights.js         
│       ├── customers.js        
│       ├── sales.js            
│       └── settings.js         
├── ADMIN/                      
│   ├── index.html              
│   ├── dashboard.html
│   ├── sales.html
│   ├── arima.html
│   ├── inventory.html
│   ├── customers.html
│   ├── suppliers.html
│   ├── users.html
│   └── settings.html
├── CASHIER/                    
│   ├── index.html              
│   ├── customer-custody.html
│   ├── products-intake.html
│   └── payment-print.html
└── database/
    └── schema.sql              
```

## Roadmap

The roadmap below reflects the full intended system. Checked items are done **as
client-side prototype behaviour**; unchecked items still require the backend, the
database, or the analytics worker to exist.

### Phase 0 - Foundation, UI/UX Design & Frontend

Status: Complete - 2026-09-27

- [x] Initialized Git repository and migrated UI mockups.
- [x] Converted styles globally to pure Vanilla CSS (`css/styles.css`).
- [x] Organized frontend into modular subsystem folders (`js/data/`, `js/auth/`, `js/pos/`, `js/admin/`).
- [x] Built the Cashier POS terminal as a 3-step wizard, one HTML file per step (`CASHIER/`).
- [x] Built the Owner Dashboard as one HTML file per tab (`ADMIN/`).
- [x] Built login interfaces for Cashier and Owner (`index.html`, `admin-login.html`).
- [x] Consolidated the codebase onto one shared stylesheet and one shared module tree.
- [x] Drafted the initial MySQL relational database schema (`database/schema.sql`).

Deliverables:
- Clean, semantic HTML/CSS/JS front-end prototype
- Modular client-side directory structure under a single shared `js/` and `css/`
- Database schema drafted for a future local trial

---

### Phase 1 - Cashier POS Core & Transaction Processing

Status: Client-side prototype complete; database and server work outstanding
Target: Sprint 1

- [x] Customer lookup and selection (from the demo dataset)
- [x] Customer debt ledger calculation and balance display
- [x] Container type segregation for Slim and Round 5-gallon bottles
- [x] Container custody intake validation (Issued OUT vs. Returned IN deficit and billing)
- [x] Empty jug return deposit credit discount calculation
- [x] Consumable auto-deduction (caps and seals per refilled gallon)
- [x] Cart management, item pricing, and 12% VAT computation
- [x] Transaction checkout (Cash payment vs. Charge to Account / Utang)
- [x] Customer debt settlement workflow with balance updates (client-side)
- [x] Customer quick registration (client-side record)
- [x] Itemized transaction records (client-side)
- [x] Physical inventory count deduction upon order completion (client-side)
- [x] Thermal receipt generation and print-ready formatting
- [x] Input validation and payment error handling
- [ ] Persist transactions, custody logs, and inventory changes to MySQL
- [ ] Production queue stage advancement (Unload, Wash, Fill, Seal)

Deliverables:
- Fully functional POS transactional engine *(client-side only)*
- Real-time container custody tracking and inventory deduction *(client-side only)*
- Persistent sales and debt audit trail *(not yet — requires the database layer)*

---

### Phase 2 - Owner Management Dashboard & Data Sync

Status: Client-side prototype complete; live database sync outstanding
Target: Sprint 2

- [x] Aggregation of station KPIs (revenue, gallons, debts, restock alerts) from demo data
- [x] Inventory tracking with stock status indicators (client-side)
- [x] Returnable container custody ledger for customer bottle liabilities
- [x] Customer credit balance and recovery tracking (client-side)
- [x] Supplier lead-time configuration and station parameters persistence (`localStorage`)
- [x] Automated restock advisories based on reorder thresholds (client-side)
- [x] Next-7-days action advisory generator (client-side)
- [x] CSV and tabular audit report export (client-side download)
- [ ] Serve dashboard data from the database via the REST API
- [ ] Server-side role-based access control and session management

Deliverables:
- Operational Owner Dashboard *(client-side demo, not yet powered by live queries)*
- Customer liability and bottle loss-prevention ledger *(client-side)*
- Automated stockout warning and replenishment advisory feed *(client-side)*

---

### Phase 3 - ARIMA Demand Forecasting Engine (Tier 3)

Status: Not started - UI placeholder only
Target: Sprint 3

The dashboard currently **displays** a forecast, but the values are hard-coded sample
data. Nothing in this phase exists in code yet.

- [ ] Transaction time-series aggregation (daily/weekly sales totals from the database)
- [ ] Time-series cleaning, zero-sales handling, and outlier smoothing
- [ ] Stationarity testing and differencing
- [ ] Autocorrelation and partial autocorrelation analysis
- [ ] Optimal AR/MA parameter selection
- [ ] Residual diagnostics and model validation
- [ ] 7-day forward demand forecasting across daily, weekly, and monthly horizons
- [ ] Multi-item forecasting for consumables (caps, seals, filter cycles)
- [ ] Model accuracy benchmarking against historical sales
- [ ] REST API endpoint serving live forecast projections to the dashboard
- [ ] Persistence of forecast outputs into database tables

Deliverables:
- Standalone statistical forecasting worker
- Forecast API endpoint powering the dashboard demand visualization
- Statistical model accuracy evaluation report

---

### Phase 4 - Dynamic Inventory Optimization & ROP Engine

Status: Partial - simple client-side reorder-point recalculation only
Target: Sprint 3

- [x] Reorder-point recalculation from supplier lead time (client-side, fixed coefficients)
- [x] Low-stock trigger generating restock advisories (client-side)
- [ ] Dynamic safety stock calculated from forecast variance and lead times
- [ ] Priority-based safety factor adjustments for essential categories
- [ ] Recalculation job driven by updated demand forecasts
- [ ] Purchase-order quantity recommendation based on maximum stock targets

Deliverables:
- Dynamic Safety Stock and Reorder Point calculation worker *(not yet)*
- Automated replenishment trigger and stockout warning system *(partial, client-side)*
- Predictive inventory optimization feed *(not yet)*

---

### Phase 5 - Testing and System Evaluation

Status: Not started
Target: Sprint 4

- [ ] Black Box functional testing across all POS and Admin modules
- [ ] Transactional integrity and concurrency testing for POS checkouts
- [ ] Forecast accuracy verification against historical sales data
- [ ] Usability evaluation via System Usability Scale (SUS) survey
- [ ] ISO/IEC 25010 software quality compliance evaluation
- [ ] Stress and throughput benchmarking for database transactions

Deliverables:
- Usability survey evaluation report
- Software quality compliance document
- Statistical forecasting accuracy validation report

---

### Phase 6 - Backend Integration & Deployment

Status: Not started - database schema drafted only
Target: Post-Sprint 4

- [x] Drafted the initial MySQL relational database schema (`database/schema.sql`)
- [ ] Backend server routes connecting the front-end to MySQL
- [ ] Containerize all 4 tiers with Docker:
  - [ ] Tier 1: Presentation Layer (static web server)
  - [ ] Tier 2: Application API Layer (backend service)
  - [ ] Tier 3: ARIMA Analytics Engine (forecasting service)
  - [ ] Tier 4: Database Layer (MySQL with persistent data volume)
- [ ] Multi-container orchestration via Docker Compose
- [ ] Environment variables configuration template
- [ ] Deployment and installation manual

Deliverables:
- Fully integrated full-stack system connected to MySQL
- Production deployment configuration

---

## Changelog

### 2026-09-27
- Split the Cashier terminal into one HTML file per wizard step (`CASHIER/`) with shared POS state across pages.
- Split the Owner Dashboard into one HTML file per tab (`ADMIN/`).
- Consolidated the codebase onto one shared stylesheet (`css/styles.css`) and one shared module tree (`js/data/`, `js/auth/`, `js/pos/`, `js/admin/`).
- Removed superseded prototype files and empty placeholder scripts.
- Drafted the MySQL relational database schema (`database/schema.sql`).
