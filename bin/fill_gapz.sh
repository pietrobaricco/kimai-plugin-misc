#!/bin/bash

cd /opt/webapps/kimai2

sudo -u www-data php bin/console pbaricco:fill-gaps --customer_id=1 --day=2023-01-01