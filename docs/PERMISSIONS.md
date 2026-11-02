# Who can do what

Generated from the database by `php tools/permissions_report.php`. **R** read, **C** create, **U** update, **D** delete, **S** scan.
The database checks every one of these on every call; the menu only hides links.

| Role | Accounts | BOMs | Customers | Dashboard | Dispatch | Items | Production | PurchaseOrders | QC | SalesOrders | Stock | Suppliers | WorkOrders | Workstations |
|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|
| Accounts | CDRU |  | R | R |  | R |  | R |  | R |  | R |  |  |
| Admin | all | all | all | all | all | all | all | all | all | all | all | all | all | all |
| Dispatch |  |  |  |  | CRS |  |  |  |  | R | R |  |  |  |
| Manager | R | R | R | R | R | R | R | CRU | R | CRU | R | R | CRU | R |
| Operator |  |  |  |  |  | R | CR |  |  |  | S |  | R | R |
| Production |  | R |  | R |  | R | CRU |  |  | R | R |  | CRU | RU |
| QC |  |  |  |  |  | R | R |  | CRU |  | R |  |  |  |
| Sales |  |  | CRU | R |  | R |  |  |  | CRU | R |  |  |  |
| Store |  |  |  |  |  | R |  | RU |  |  | CRS | R |  |  |

## Things worth a second look

- Accounts can cancel invoices and payments (Accounts delete).

Admin holds the wildcard (every resource, every action), including users and settings.
