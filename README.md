# Tekraportti

**Open-source technical reporting and documentation software**

[![License: AGPL v3](https://img.shields.io/badge/License-AGPL_v3-blue.svg)](https://www.gnu.org/licenses/agpl-3.0)

**Developed and maintained by Koodiportti**  
Business ID: FI32033835  
Website: https://tekraportti.com  
License: GNU Affero General Public License v3.0

---

## Overview

Tekraportti is a web-based application designed for creating, managing and publishing professional technical reports.

The application provides a structured reporting workflow that combines written observations, photographs, document attachments and supporting information into PDF reports.

Tekraportti is intended for technical inspections, property condition assessments, maintenance documentation and other professional reporting activities.

The software is developed and maintained by Koodiportti, Finland.

## Features

### Report Management

- Create and manage technical reports
- Organize reports by customer
- Edit and update existing reports
- Manage report sections and content
- Use reusable report templates
- Preview reports before publication

### Report Structure

Reports can contain:

- Cover page
- Executive summary
- Table of contents
- Structured report sections
- Text and technical observations
- Photographs and supporting media
- Supporting PDF documents
- Report author information

### Images and Annotations

Tekraportti supports image-based reporting, including:

- Image uploads
- Image annotations
- Arrows and geometric markings
- Text annotations
- Image processing for PDF generation

### PDF Processing

The application provides:

- PDF report generation
- Importing existing PDF pages
- PDF text extraction
- PDF document analysis
- PDF compression and optimization

### Customer Management

- Customer-specific reporting
- Report organization by customer
- Centralized report management

## Technology Stack

Tekraportti is primarily developed using:

- PHP 8
- HTML5
- CSS3
- JavaScript
- MariaDB / MySQL
- Apache HTTP Server
- Bootstrap
- jQuery

The application is designed for a Linux-based web server environment.

### PHP Libraries

| Library | Purpose | License |
|---|---|---|
| FPDF | PDF generation | FPDF permissive license |
| FPDI | Importing PDF documents | MIT |
| PHPMailer | SMTP and email communication | LGPL 2.1 |

### Frontend Libraries

| Library | Purpose | License |
|---|---|---|
| Bootstrap | Responsive user interface | MIT |
| jQuery | JavaScript functionality | MIT |

### External Utilities

| Utility | Purpose | License |
|---|---|---|
| Ghostscript | PDF compression | AGPL v3 / commercial |
| Poppler | PDF text extraction | GPL-family licenses |

Each third-party component remains subject to its respective licensing conditions.

## System Requirements

Recommended server environment:

- Linux server, such as Ubuntu 24.04 LTS
- Apache HTTP Server
- PHP 8.3 or compatible version
- MariaDB or MySQL
- PHP PDO MySQL extension
- PHP GD extension
- PHP cURL extension
- PHP mbstring extension
- PHP fileinfo extension
- Poppler utilities
- Ghostscript, when PDF compression is enabled

The requirements may vary depending on the installed version and enabled features.

## Installation

### 1. Obtain the Source Code

Clone the project repository:

```bash
git clone <repository-url> tekraportti
cd tekraportti
```

Replace `<repository-url>` with the official GitHub repository URL.

### 2. Configure the Web Server

Configure Apache to serve the Tekraportti application.

Ensure that PHP is enabled and that the application has access to its required PHP extensions and system utilities.

HTTPS is recommended for all production deployments.

Restrict access to private files, configuration data and customer uploads.

### 3. Configure the Database

Create a dedicated MariaDB or MySQL database.

Import the database schema supplied with the application:

```bash
mysql -u <db_user> -p <database_name> < tietokanta.sql
```

Apply additional database migration files as required for the installed application version.

Use a dedicated database account with only the permissions required for normal application operation.

Database schema changes should be performed using an appropriately authorized maintenance account.

### 4. Configure Private Credentials

Tekraportti uses a private PHP configuration file containing database connection credentials.

The production configuration must not be committed to a public repository.

Create a `secret.php` file outside the web server's public document root.

Example configuration:

```php
<?php

$mysqluser = 'database_username';
$database = 'database_name';
$passwd = 'database_password';
```

The configuration path must match the path expected by the application's PHP files.

Use placeholder values in example configuration files. Never publish real production credentials.

The file must have appropriately restricted filesystem permissions.

### 5. Install Required Utilities

On Ubuntu, the PDF processing utilities can be installed using:

```bash
sudo apt update
sudo apt install poppler-utils ghostscript
```

Ghostscript use is subject to its applicable AGPL or commercial licensing requirements.

### 6. Configure Application Permissions

Ensure that the PHP process has the necessary permissions for application operation, including authorized report generation and file uploads.

Do not grant unnecessary write permissions to application source files.

Protect configuration files, private uploads and customer data from public web access.

### 7. Complete the Deployment

Before making the application publicly accessible:

- Verify database connectivity
- Verify required PHP extensions
- Verify PDF generation
- Verify PDF text extraction
- Verify email functionality
- Verify file upload security
- Enable HTTPS
- Review application and web server permissions

Production deployment should be tested before use with customer data.

## Security

Tekraportti processes customer information and may contain confidential reporting materials.

Administrators should follow standard security practices, including:

- HTTPS encryption
- Secure authentication
- Restricted database privileges
- Protected private configuration files
- Input validation and output escaping
- Appropriate file upload restrictions
- Regular system and dependency updates
- Secure database and file backups
- Appropriate access controls

Production credentials, encryption keys, authentication tokens and private customer information must never be committed to public repositories.

Security-sensitive information should not be included in publicly accessible documentation.

Security reports should be sent privately to the project maintainer rather than published with exploitable details.

## Artificial Intelligence Assistance

Tekraportti has been developed with the assistance of artificial intelligence tools.

AI-assisted development has been used for:

- Software code generation
- Debugging and code analysis
- Technical documentation
- User interface development
- Software architecture assistance
- Graphical content generation

Koodiportti directs and manages the development, reviews and release decisions.

AI tools are not identified as copyright holders.

The use of artificial intelligence does not change the applicable software license or third-party licensing obligations.

## Open Source License

Tekraportti is released under the **GNU Affero General Public License version 3.0 (AGPL-3.0-only)**.

The license permits:

- Commercial use
- Modification
- Redistribution
- Private use
- Hosting and operation of the software

These activities are subject to the conditions of the AGPL.

In particular, applicable source code availability obligations must be respected when operating modified versions over a computer network.

See the complete license in:

[LICENSE.md](LICENSE.md)

Official AGPL v3 license:

https://www.gnu.org/licenses/agpl-3.0.html

## Third-Party Licenses

Tekraportti incorporates or uses third-party software components with their own licenses.

The original license notices of redistributed third-party libraries must be preserved.

Additional information is available in:

[COPYRIGHT.md](COPYRIGHT.md)

Key dependencies include:

- [FPDF](https://www.fpdf.org/)
- [FPDI](https://www.setasign.com/products/fpdi/)
- [PHPMailer](https://github.com/PHPMailer/PHPMailer)
- [Bootstrap](https://getbootstrap.com/)
- [jQuery](https://jquery.com/)
- [Ghostscript](https://ghostscript.com/)
- [Poppler](https://poppler.freedesktop.org/)

Ghostscript and Poppler are separate utilities subject to their respective licensing terms.

## User Content and Privacy

Reports, photographs, videos, uploaded files and customer information created or processed using Tekraportti do not automatically become open-source materials.

Customer and user-generated content remains subject to applicable ownership rights, privacy requirements and contractual terms.

The AGPL software license does not authorize disclosure of customer databases, confidential documents or personal information.

## Trademarks and Branding

The Tekraportti and Koodiportti names and identifying marks are subject to applicable trademark and other laws.

The software license does not automatically grant unrestricted rights to use these names or marks in a manner suggesting official affiliation or endorsement.

## Contributing

Contributions, bug reports and improvement suggestions are welcome through the project's GitHub repository.

Contributors must ensure that submitted code and other materials can lawfully be incorporated into the project under its applicable licensing terms.

Third-party copyright notices and licensing conditions must be preserved.

Changes to the official project are reviewed and accepted by its maintainers.

## Warranty Disclaimer

Tekraportti is provided **AS IS**, without warranty of any kind, to the extent permitted by applicable law.

See Sections 15–17 of the GNU Affero General Public License version 3 for the applicable warranty disclaimer and limitation of liability.

## Maintainer

**Koodiportti**  
Business ID: FI32033835  
Finland

Website: https://tekraportti.com

---

Copyright © 2026 Koodiportti.

Licensed under the GNU Affero General Public License v3.0 (`AGPL-3.0-only`).
