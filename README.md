# A Kimai 2 bundle

A Kimai 2 plugin, which contains some functionalities I needed and didn't find in the base product.
Mostly, the capability to programmatically export and send via emails reports of the timesheets on a weekly or monthly basis.

## Installation

First clone it to the `plugins/PbariccoBundle` directory, relative to your Kimai installation :
```
cd /kimai/var/plugins/
git clone https://github.com/pietrobaricco/kimai-plugin-misc.git PbariccoBundle
```

Rebuild the cache:
```
cd /kimai/
bin/console kimai:reload -n
```

## The export command

Now you can run the export command, for example:

```
php bin/console kimai:export:timesheet johndoe xlsx --customer_id=1 --period=week-1 --target_file="/home/johndoe/Desktop/timesheet.xlsx" --hide_rates=1 --custom_title="Bla" --email_to="john.doe@example.com"
```

check the command source for a full list of the supported options