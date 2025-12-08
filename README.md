# Sendy-Beem Integration System

## Overview

This system integrates Sendy (email marketing platform) with Beem (WhatsApp messaging service) to create powerful multi-channel marketing campaigns. It allows you to automatically send WhatsApp messages and emails to subscribers through flexible drip campaigns.

## Key Features

- **WhatsApp Drip Campaigns**: Send scheduled WhatsApp messages using Beem's template system
- **Email Drip Campaigns**: Send scheduled emails through Sendy's campaign API
- **Hybrid Campaigns**: Alternate between email and WhatsApp in a single campaign sequence
- **Webhook Integration**: Automatically triggered when subscribers join Sendy lists
- **Flexible Scheduling**: Configure delays in minutes, hours, days, weeks, or months
- **Template Management**: Map Sendy lists to Beem WhatsApp templates
- **Content Personalization**: Dynamic content replacement with subscriber data
- **Automated Processing**: Cron-based queue processor for reliable message delivery

## System Architecture

### Components

1. **Webhook Handler**: Receives subscription events from Sendy
2. **Mode Handlers**: Process different campaign types (whatsapp_drip, email_drip, hybrid_drip)
3. **Queue Processor**: Cron job that sends scheduled messages
4. **Storage Service**: JSON-based file storage for campaigns and configurations
5. **API Services**: Beem and Sendy API integrations

### Campaign Modes

#### WhatsApp Drip Mode
Sends a sequence of WhatsApp messages using Beem templates.

#### Email Drip Mode
Sends a sequence of emails through Sendy's campaign API.

#### Hybrid Drip Mode
Alternates between email and WhatsApp messages in a unified campaign.

## Installation

### Requirements

- PHP 8.0 or higher
- cURL extension enabled
- File write permissions for data directory
- Sendy installation with API access
- Beem Africa account with WhatsApp Business API

### Setup Steps

1. **Clone or download the system files**

2. **Configure environment variables**
   ```bash
   cp .env.example .env
   ```

3. **Edit .env file with your credentials**
   ```dotenv
   # Beem Configuration
   BEEM_API_KEY=your_beem_api_key
   BEEM_SECRET_KEY=your_beem_secret_key
   BEEM_SENDER_NUMBER=your_whatsapp_number
   BEEM_API_BASE_URL=beem_whatsapp_template_fetching_endpoint
   BEEM_BROADCAST_URL=beem_whatsapp_broadcast_endpoint
   BEEM_USER_ID_FOR_TEMPLATES=your_user_id
   BEEM_API_TOKEN=your_api_token

   # Sendy Configuration
   SENDY_API_KEY=your_sendy_api_key
   SENDY_URL=https://your-sendy-installation.com
   SENDY_PHONE_FIELD_NAME=CustomField1
   SENDY_GET_BRANDS_URL=/api/brands/get-brands.php
   SENDY_GET_LISTS_URL=/api/lists/get-lists.php
   SENDY_CREATE_CAMPAIGN_URL=/api/campaigns/create.php

   # Email Defaults
   DEFAULT_FROM_NAME=Your Company
   DEFAULT_FROM_EMAIL=noreply@yourcompany.com

   # Application
   APP_DEBUG=false
   ```

4. **Create data directory**
   ```bash
   mkdir -p data
   chmod 755 data
   ```

5. **Set up cron job**
   Add to your crontab:
   ```bash
   # Process drip queues every 5 minutes
   */5 * * * * /usr/bin/php /path/to/your/installation/cron.php
   ```

6. **Configure Sendy webhook**
   In your Sendy list settings, add webhook URL:
   ```
   https://yourdomain.com/webhook.php
   ```

## Configuration

### Template Mapping

Map Sendy lists to Beem WhatsApp templates using the admin interface or by editing `data/template_map.json`:

```json
{
  "list_abc123": {
    "template_id": "template_xyz789",
    "params": ["name", "email"]
  }
}
```

### Webhook Configuration

Configure campaign behavior in `data/webhooks.json`:

```json
{
  "list_abc123": {
    "mode": "hybrid_drip",
    "sequence": [
      {
        "channel": "email",
        "subject": "Welcome to our community!",
        "html_text": "<h1>Hi [name]!</h1><p>Welcome aboard.</p>",
        "delay": 0,
        "delay_unit": "minutes"
      },
      {
        "channel": "whatsapp",
        "template_id": "template_xyz789",
        "params": ["name"],
        "delay": 2,
        "delay_unit": "hours"
      },
      {
        "channel": "email",
        "subject": "Getting Started Guide",
        "html_text": "<p>Here's how to get started...</p>",
        "delay": 1,
        "delay_unit": "days"
      }
    ]
  }
}
```

## Campaign Configuration Examples

### WhatsApp Drip Campaign

```json
{
  "list_id_here": {
    "mode": "whatsapp_drip",
    "template_id": "welcome_template",
    "sequence": [
      {
        "template_id": "welcome_message",
        "params": ["name"],
        "delay": 0,
        "delay_unit": "minutes",
        "name": "Welcome Message"
      },
      {
        "template_id": "onboarding_day1",
        "params": ["name", "company"],
        "delay": 1,
        "delay_unit": "days",
        "name": "Day 1 Onboarding"
      },
      {
        "template_id": "onboarding_day3",
        "params": ["name"],
        "delay": 3,
        "delay_unit": "days",
        "name": "Day 3 Check-in"
      }
    ]
  }
}
```


### Hybrid Campaign

```json
{
  "list_id_here": {
    "mode": "hybrid_drip",
    "sequence": [
      {
        "channel": "email",
        "subject": "Welcome!",
        "html_text": "<h1>Hi [name]</h1>",
        "delay": 0,
        "delay_unit": "minutes"
      },
      {
        "channel": "whatsapp",
        "template_id": "followup_template",
        "params": ["name"],
        "delay": 2,
        "delay_unit": "hours"
      },
      {
        "channel": "email",
        "subject": "Next Steps",
        "html_text": "<p>Ready to continue?</p>",
        "delay": 1,
        "delay_unit": "days"
      }
    ]
  }
}
```

## Configuration Fields Reference

### WhatsApp Step Fields

- **template_id** (required): Beem template identifier
- **params** (optional): Array of parameter values for template
- **delay** (required): Number of time units to wait
- **delay_unit** (optional): minutes, hours, days, weeks, months (default: days)
- **name** (optional): Descriptive name for logging

### Email Step Fields

- **subject** (required): Email subject line
- **html_text** (required): HTML email content
- **plain_text** (optional): Plain text version
- **from_name** (optional): Sender name (falls back to default)
- **from_email** (optional): Sender email (falls back to default)
- **reply_to** (optional): Reply-to email address
- **title** (optional): Campaign title in Sendy
- **list_id** (optional): Target list (falls back to subscriber's list)
- **track_opens** (optional): 0=disabled, 1=enabled, 2=anonymous (default: 1)
- **track_clicks** (optional): 0=disabled, 1=enabled, 2=anonymous (default: 1)
- **query_string** (optional): UTM parameters for tracking
- **delay** (required): Number of time units to wait
- **delay_unit** (optional): minutes, hours, days, weeks, months (default: days)

### Personalization Tags

Use these tags in email content and they will be replaced with subscriber data:

- `[name]` or `{name}` - Subscriber's name
- `[email]` or `{email}` - Subscriber's email address

## API Endpoints

### Webhook Endpoint

**URL**: `/webhook.php`

**Method**: POST

**Triggered by**: Sendy subscription events

**Response**: JSON with status and details

### Admin Interface

**URL**: `/admin.php`

**Features**:
- View and configure template mappings
- Monitor queue statistics
- View recent activity logs
- Test webhook configurations

### Queue Statistics

**URL**: `/api/stats.php`

**Method**: GET

**Response**:
```json
{
  "whatsapp_drip": {
    "total": 150,
    "active": 45,
    "completed": 105,
    "pending_messages": 180
  },
  "email_drip": {
    "total": 200,
    "active": 60,
    "completed": 140,
    "pending_messages": 240
  },
  "hybrid_drip": {
    "total": 100,
    "active": 30,
    "completed": 70,
    "pending_messages": 90,
    "pending_email": 45,
    "pending_whatsapp": 45
  }
}
```

## Monitoring and Maintenance

### Log Files

The system logs to `data/error.log`. Monitor this file for:
- API errors
- Queue processing status
- Campaign completion
- Failed message attempts

### Queue Management

Messages that fail are automatically retried after 5 minutes. After multiple failures, check:
- API credentials are correct
- Beem templates exist and are approved
- Sendy lists are properly configured
- Network connectivity

### Database Cleanup

Completed campaigns are automatically removed after 30 days. This can be adjusted in the handler classes.

## Troubleshooting

### Messages Not Sending

1. Verify cron job is running: Check system cron logs
2. Check API credentials in .env file
3. Review error.log for specific errors
4. Ensure data directory is writable
5. Verify webhook is receiving events from Sendy

### WhatsApp Messages Failing

1. Confirm Beem templates are approved
2. Check template parameters match configuration
3. Verify phone numbers are in E.164 format (+254...)
4. Ensure sender number is registered with Beem

### Email Messages Failing

1. Verify Sendy API key is correct
2. Check from_email is authorized in Sendy
3. Ensure list_ids are valid
4. Confirm Sendy cron job is running

### Webhook Not Triggering

1. Test webhook URL is accessible
2. Check Sendy webhook configuration
3. Verify URL includes proper protocol (https://)
4. Review Sendy logs for webhook errors

## Security Considerations

1. **Protect .env file**: Never commit to version control
2. **Secure data directory**: Set appropriate file permissions
3. **Use HTTPS**: Always use secure connections for webhooks
4. **API Key Security**: Rotate keys regularly
5. **Input Validation**: System validates all configuration data
6. **Rate Limiting**: Consider implementing if receiving high webhook traffic

## Performance Optimization

### For High Volume

1. Increase cron frequency for faster processing
2. Implement database storage instead of JSON files
3. Add queue workers for parallel processing
4. Monitor and optimize API rate limits
5. Implement caching for frequently accessed data

### Recommended Cron Schedule

```bash
# Low volume (up to 1000 subscribers)
*/5 * * * * /usr/bin/php /path/to/cron.php

# Medium volume (1000-10000 subscribers)
*/2 * * * * /usr/bin/php /path/to/cron.php

# High volume (10000+ subscribers)
* * * * * /usr/bin/php /path/to/cron.php
```

## Testing

### Test Webhook Configuration

1. Add a test subscriber to your Sendy list
2. Check `data/error.log` for webhook receipt
3. Verify queue entry in appropriate JSON file
4. Monitor message delivery in next cron run

### Test Template Mapping

1. Configure a simple template map
2. Subscribe a test contact
3. Verify message is queued correctly
4. Check Beem dashboard for delivery status

## Support and Maintenance

### Regular Tasks

1. Monitor log files weekly
2. Review queue statistics daily
3. Clean up old log files monthly
4. Update API credentials as needed
5. Test campaigns before major deployments

### Backup Recommendations

Regularly backup these critical files:
- `.env`
- `data/webhooks.json`
- `data/template_map.json`
- `data/*_queue.json`

## License and Credits

This system integrates with:
- Sendy (https://sendy.co) - Self-hosted email newsletter application
- Beem Africa (https://beem.africa) - WhatsApp Business API provider

## Version History

**v1.0.0** - Initial release
- WhatsApp drip campaigns
- Email drip campaigns
- Hybrid multi-channel campaigns
- Webhook integration
- Template mapping
- Automated queue processing