# Web of Influence - PHP Deployment Guide

## Overview

This guide covers the complete redesign of the Web of Influence database system to work with PHP and a single MySQL database on the ludogogy.co.nz server.

## Architecture Changes

### From Python + Multiple Databases → PHP + Single Database

**Previous Architecture:**
- Python Flask API with multiple separate databases
- Complex multi-database queries and connections
- Separate databases for donations, meetings, candidates, etc.

**New Architecture:**
- PHP REST API with single MySQL database
- Centralized `ludog319_webofinfluence` database
- Simplified data relationships and foreign keys
- Optimized for shared hosting environments

## Database Schema

### Core Tables

1. **people** - Central person registry
   - Stores all individuals (candidates, ministers, donors)
   - Auto-increment ID with unique constraints

2. **parties** - Political parties
3. **electorates** - Electoral districts
4. **donors** - Donation sources (individuals and organizations)

5. **candidate_overview** - Candidate election data by year
   - Links to people, parties, electorates
   - Financial summary data (donations, expenses)

6. **donations** - Individual donation records
   - Links to candidates, donors, and candidate_overview
   - Detailed transaction information

7. **meetings** - Ministerial meeting records
   - Links to ministers (people table)
   - Meeting details, locations, participants

## File Structure

```
deploy/
├── php-api/
│   ├── index.php          # Main API endpoint router
│   ├── config.php         # Database configuration
│   ├── config.example.php # Configuration template
│   ├── dbtest.php         # Database connection test
│   └── .htaccess          # Apache URL rewriting
├── .htaccess              # Main site routing
└── index.html             # Frontend application

csv_data/
├── import_all_csvs.sql    # Complete CSV import script
└── [csv files...]         # Source data files

Documentation/
├── sql/
│   ├── woi_schema_singledb.sql    # New single database schema
│   └── woi_migration_from_legacy.sql # Migration from old system
└── php_deployment_guide.md        # This guide
```

## Deployment Steps

### 1. Database Setup

1. Create the database using cPanel MySQL Databases:
   ```sql
   Database Name: ludog319_webofinfluence
   Database User: ludog319_woi_user (with full privileges)
   ```

2. Run the schema creation script:
   ```bash
   mysql -u ludog319_woi_user -p ludog319_webofinfluence < Documentation/sql/woi_schema_singledb.sql
   ```

3. Import CSV data:
   ```bash
   mysql -u ludog319_woi_user -p ludog319_webofinfluence < csv_data/import_all_csvs.sql
   ```

### 2. PHP API Configuration

1. Copy configuration file:
   ```bash
   cp deploy/php-api/config.example.php deploy/php-api/config.php
   ```

2. Update database credentials in `deploy/php-api/config.php`:
   ```php
   $config = [
       'database' => [
           'host' => 'localhost',
           'database' => 'ludog319_webofinfluence',
           'username' => 'ludog319_woi_user',
           'password' => 'your_secure_password'
       ],
       'cors' => [
           'allowed_origins' => ['https://ludogogy.co.nz', 'http://localhost:5173']
       ]
   ];
   ```

3. Test database connection:
   ```
   https://ludogogy.co.nz/php-api/dbtest.php
   ```

### 3. Frontend Configuration

1. Update API endpoint in frontend configuration:
   ```javascript
   // deploy/app-config.js
   window.appConfig = {
       apiBaseUrl: 'https://ludogogy.co.nz/php-api',
       apiVersion: 'v1'
   };
   ```

2. Ensure CORS headers are properly configured in PHP API.

## API Endpoints

### Available Endpoints

#### Donations
- `GET /api/donations` - Get all donations with filtering
- `GET /api/donations/summary` - Get donation summary statistics
- `GET /api/donations/top-donors` - Get top donors by amount
- `GET /api/donations/top-recipients` - Get top recipients by amount

#### Candidates
- `GET /api/candidates` - Get candidate overview data
- `GET /api/candidates/{id}` - Get specific candidate details
- `GET /api/candidates/search` - Search candidates

#### Meetings
- `GET /api/meetings` - Get ministerial meetings
- `GET /api/meetings/search` - Search meetings by criteria

#### Reference Data
- `GET /api/parties` - Get all political parties
- `GET /api/electorates` - Get all electorates
- `GET /api/people` - Get all people

### Query Parameters

Common parameters across endpoints:
- `year` - Filter by election year
- `party` - Filter by party name
- `electorate` - Filter by electorate
- `limit` - Limit results (default: 100)
- `offset` - Pagination offset
- `search` - Text search

Example:
```
GET /api/donations?year=2023&party=Labour&limit=50
```

## Performance Optimizations

### Database Indexes

The import script creates optimized indexes:
- Candidate overview: year, people_id, party_id, electorate_id
- Donations: year, candidate_person_id, donor_id, date, amount
- Meetings: date, minister_person_id, location
- People: first_name, last_name
- Donors: normalized_name

### Caching Strategy

PHP API includes basic caching:
- Reference data cached for 1 hour
- Query results cached based on parameters
- Cache headers for browser caching

## Data Import Process

### CSV File Processing

The import script handles:
1. **Candidate Overview Data** - All election years (2011-2023)
2. **Donation Details** - Individual transaction records
3. **Ministerial Meetings** - Andrew Hoggard's diary data

### Data Cleaning

Automatic data normalization:
- Currency formatting (removes $, commas)
- Name standardization
- Date parsing and validation
- Duplicate detection and merging

### Verification Queries

The script includes verification queries to check:
- Record counts per table
- Data quality metrics
- Top donors and recipients
- Year coverage

## Troubleshooting

### Common Issues

1. **Database Connection Errors**
   - Check credentials in config.php
   - Verify database exists and user has privileges
   - Test with dbtest.php

2. **CORS Errors**
   - Update allowed_origins in config.php
   - Check .htaccess files for proper headers

3. **CSV Import Failures**
   - Ensure LOCAL INFILE is enabled
   - Check file paths in import script
   - Verify CSV file formats

4. **Performance Issues**
   - Check if indexes were created
   - Monitor query execution times
   - Consider result set pagination

### Debug Mode

Enable debug mode in config.php:
```php
$config['debug'] = true;
```

This provides detailed error messages and query logging.

## Security Considerations

1. **Database Access**
   - Use least-privilege database user
   - Regular password rotation
   - Connection over SSL when possible

2. **API Security**
   - Input validation and sanitization
   - SQL injection prevention (prepared statements)
   - Rate limiting considerations

3. **File Security**
   - Protect config.php from web access
   - Regular security updates
   - Backup strategy

## Maintenance

### Regular Tasks

1. **Data Updates**
   - Import new CSV data as available
   - Update reference tables (parties, electorates)
   - Archive old data if needed

2. **Performance Monitoring**
   - Monitor query performance
   - Review slow query logs
   - Update indexes as needed

3. **Backups**
   - Regular database backups
   - Test restore procedures
   - Document backup schedule

## Migration from Previous System

For sites currently using the Python/multi-database system:

1. Export existing data using provided migration scripts
2. Run the new schema creation
3. Import legacy data using migration tools
4. Update frontend configuration
5. Test all functionality before switching

The migration script `woi_migration_from_legacy.sql` provides automated conversion from the old database structure.

## Support

For deployment issues or questions:
1. Check the troubleshooting section above
2. Review server error logs
3. Test individual components (database, API, frontend)
4. Verify configuration files

This deployment represents a significant simplification and performance improvement over the previous multi-database Python system, while maintaining all functionality and improving maintainability.
