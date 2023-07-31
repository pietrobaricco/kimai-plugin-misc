#!/bin/bash

cd /opt/webapps/kimai2
email="pietro.baricco@gmail.com"
user=pietro
format=xlsx
period="month-1"

sudo -u www-data php bin/console kimai:export:timesheet $user $format --customer_id=1 --period=$period --hide_rates=1 --custom_title="Swag" --email_to="$email"
sudo -u www-data php bin/console kimai:export:timesheet $user $format --customer_id=2 --period=$period --hide_rates=1 --custom_title="Intrum" --email_to="$email"
sudo -u www-data php bin/console kimai:export:timesheet $user $format --customer_id=3 --period=$period --hide_rates=1 --custom_title="Axis" --email_to="$email"