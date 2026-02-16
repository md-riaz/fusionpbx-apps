# FusionPBX Business Reports

Business-grade call reporting with saved views, inbound/outbound/local classification, and dynamic filtering.

## Features

- **Saved Views**: Create and save custom report configurations
- **Call Type Classification**: Automatic classification of Inbound, Outbound, and Local (Internal) calls
- **Dynamic Metrics**: 
  - Total Calls (Dial/Attempts)
  - Connected Calls
  - Not Connected Calls
  - Talk Time
  - ASR (Answer Seizure Ratio)
  - ACD (Average Call Duration)
  - Average Ring Time
  - Breakdown by hangup cause
- **Flexible Grouping**: Group by day/hour, extension, DID, gateway, or hangup cause
- **Dynamic Filters**: Filter by date range, call type, extension, gateway, and more
- **CSV Export**: Export report results to CSV format
- **CDR Discovery**: Automatic discovery and configuration of CDR sources
- **Performance Safeguards**: Index recommendations and query optimization

## Installation

1. Copy the `business_reports` directory to your FusionPBX apps directory:
   ```
   /var/www/fusionpbx/app/business_reports/
   ```

2. Navigate to: **Advanced > Upgrade** in FusionPBX web interface

3. Click **Schema** to create the database tables

4. Click **Permissions** to set up permissions

5. Click **Menu** to add the menu entries

6. Clear cache: **Advanced > Flush Cache**

## Initial Setup

### 1. Configure CDR Source

1. Navigate to: **Reports > Business Reports > Diagnostics**
2. The system will automatically discover available CDR tables
3. Select a CDR source to inspect (e.g., `v_xml_cdr`, `v_cdr`, or `cdr`)
4. Review the discovered columns and sample data
5. Verify or adjust the auto-detected field mappings
6. Select a Call Type Classification Mode:
   - **Direction Field**: Use if your CDR has a `direction` column
   - **Gateway Presence**: Classify based on gateway involvement
   - **Pattern Match**: Use internal extension patterns (requires configuration)
7. Choose Counting Unit:
   - **Row-based**: Fastest, but may count multiple legs
   - **Call-based**: Count unique calls (recommended if leg duplication exists)
8. Save the configuration

### 2. Review Index Recommendations

After configuration, the Diagnostics page will show SQL index recommendations. Run these commands in your database to optimize performance:

```sql
CREATE INDEX idx_cdr_domain_start ON v_xml_cdr (domain_uuid, start_stamp);
CREATE INDEX idx_cdr_domain_start_billsec ON v_xml_cdr (domain_uuid, start_stamp, billsec);
```

### 3. Create or Use Default Reports

The system creates three default report views on installation:
- **Inbound Overview**: Summary of incoming calls
- **Outbound Overview**: Summary of outgoing calls
- **Local (Internal) Overview**: Summary of internal calls

You can use these as templates or create your own custom reports.

## Usage

### Creating a Report

1. Navigate to: **Reports > Business Reports**
2. Click **New Report**
3. Configure:
   - Report Name and Description
   - Call Type (Inbound/Outbound/Local/All)
   - Date Range (last N days)
   - Grouping (by time and/or dimension)
   - Metrics to include
   - Sort order and result limit
4. Click **Create Report** to save

### Running a Report

1. From the dashboard, click on any saved report
2. The report will execute and display results in a table
3. Click **Export CSV** to download results

### Editing a Report

1. Click the Edit button next to any report
2. Modify the configuration
3. Click **Update Report** to save changes

## Call Type Classification

The system supports three modes for classifying call types:

### Mode A: Direction Field
- Uses a `direction` column in the CDR table
- Expects values like: `inbound`, `outbound`, `local`, `internal`
- Fastest and most accurate if available

### Mode B: Gateway Presence
- Classifies based on whether a gateway UUID is present
- Inbound/Outbound: Calls involving a gateway
- Local: Calls with no gateway (internal only)

### Mode C: Pattern Match
- Uses SQL LIKE patterns to identify internal extensions
- Example: `1%` matches extensions starting with 1
- Inbound: External caller → Internal destination
- Outbound: Internal caller → External destination
- Local: Internal caller → Internal destination

## Security & Multi-Tenancy

- All queries are automatically filtered by `domain_uuid`
- Users can only access reports within their domain
- Permissions:
  - `business_report_view`: View reports
  - `business_report_add`: Create new reports
  - `business_report_edit`: Edit reports
  - `business_report_delete`: Delete reports
  - `business_report_diagnostics`: Access diagnostics (superadmin only)

## Performance Considerations

### Recommended Indexes

Always create these indexes on your CDR table:
```sql
-- PostgreSQL
CREATE INDEX idx_cdr_domain_start ON cdr_table (domain_uuid, start_stamp);
CREATE INDEX idx_cdr_domain_start_billsec ON cdr_table (domain_uuid, start_stamp, billsec);
CREATE INDEX idx_cdr_domain_start_hangup ON cdr_table (domain_uuid, start_stamp, hangup_cause);
```

### Query Limits

- Default date range: Last 7 days
- Maximum date range: 90 days (configurable)
- Maximum result rows: 10,000 (hard cap)
- For large date ranges, use time grouping (by day/hour)

### Double Counting

If your CDR stores multiple legs per call (A-leg/B-leg):
- Use **Call-based** counting in Diagnostics
- Ensure the Call ID Field is correctly configured
- The system will use `COUNT(DISTINCT call_id)` instead of `COUNT(*)`

## Troubleshooting

### "CDR source not configured" Error

Run the Diagnostics tool first to configure your CDR source.

### No Results

1. Verify date range includes data
2. Check call type classification is working correctly
3. Review filters - they may be too restrictive
4. Use Debug mode: Add `?debug=1` to view_runner.php URL

### Slow Queries

1. Ensure recommended indexes are created
2. Reduce date range
3. Use time grouping for large date ranges
4. Limit the number of metrics

### Wrong Call Counts

1. Check the Counting Unit setting in Diagnostics
2. Run the Double Counting Test
3. Verify Call ID Field is correct
4. Review Call Type Classification Mode

## Limitations (V1)

- No real-time monitoring (CDR-based only)
- No advanced analytics (ML/AI)
- No REST API
- CSV export only (no PDF)
- Maximum two-dimensional grouping (time + one dimension)
- No custom SQL expressions for metrics

## Future Enhancements

Planned for future versions:
- PDF export
- Scheduled reports (email delivery)
- Dashboard widgets
- More granular filters (caller/destination patterns with operators)
- Custom metric definitions
- Multi-dimensional grouping
- Graphical charts
- Comparison reports (period over period)

## Support

For issues or questions:
1. Check the Diagnostics page for configuration errors
2. Review this README
3. Check FusionPBX forums
4. Review code comments in `/classes/` directory

## License

Mozilla Public License 1.1 (MPL 1.1)

## Credits

Developed for FusionPBX
