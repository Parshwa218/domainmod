<?php
/**
 * /reporting/ssl/cost-by-ip-address.php
 *
 * This file is part of DomainMOD, an open source domain and internet asset manager.
 * Copyright (c) 2010-2018 Greg Chetcuti <greg@chetcuti.com>
 *
 * Project: http://domainmod.org   Author: http://chetcuti.com
 *
 * DomainMOD is free software: you can redistribute it and/or modify it under the terms of the GNU General Public
 * License as published by the Free Software Foundation, either version 3 of the License, or (at your option) any later
 * version.
 *
 * DomainMOD is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY; without even the implied
 * warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along with DomainMOD. If not, see
 * http://www.gnu.org/licenses/.
 *
 */
?>
<?php //@formatter:off
require_once __DIR__ . '/../../_includes/start-session.inc.php';
require_once __DIR__ . '/../../_includes/init.inc.php';
require_once DIR_INC . '/config.inc.php';
require_once DIR_INC . '/software.inc.php';
require_once DIR_ROOT . '/vendor/autoload.php';

$currency = new DomainMOD\Currency();
$date = new DomainMOD\Date();
$deeb = DomainMOD\Database::getInstance();
$form = new DomainMOD\Form();
$layout = new DomainMOD\Layout;
$reporting = new DomainMOD\Reporting();
$system = new DomainMOD\System();
$time = new DomainMOD\Time();

require_once DIR_INC . '/head.inc.php';
require_once DIR_INC . '/debug.inc.php';
require_once DIR_INC . '/settings/reporting-ssl-cost-by-ip.inc.php';

$system->authCheck();
$pdo = $deeb->cnxx;

$export_data = $_GET['export_data'];
$daterange = $_REQUEST['daterange'];

list($new_start_date, $new_end_date) = $date->splitAndCheckRange($daterange);

if ($_SERVER['REQUEST_METHOD'] == 'POST') {

    $date = new DomainMOD\Date();

    if ($new_start_date > $new_end_date) {

        $_SESSION['s_message_danger'] .= 'The end date proceeds the start date<BR>';
        $submission_failed = '1';

    }

}

$range_string = $reporting->getRangeString('sslc.expiry_date', $new_start_date, $new_end_date);

$result = $pdo->query("
    SELECT ip.id, ip.name, ip.ip, ip.rdns, SUM(sslc.total_cost * cc.conversion) AS total_cost, count(*) AS number_of_certs
    FROM ssl_certs AS sslc, ssl_fees AS f, currencies AS c, currency_conversions AS cc, ip_addresses AS ip
    WHERE sslc.fee_id = f.id
      AND f.currency_id = c.id
      AND c.id = cc.currency_id
      AND sslc.ip_id = ip.id
      AND sslc.active NOT IN ('0')
      AND cc.user_id = '" . $_SESSION['s_user_id'] . "'" .
      $range_string . "
    GROUP BY ip.name
    ORDER BY ip.name")->fetchAll();

$total_rows = count($result);

$result_grand_total = $pdo->query("
    SELECT SUM(sslc.total_cost * cc.conversion) AS grand_total, count(*) AS number_of_certs_total
    FROM ssl_certs AS sslc, ssl_fees AS f, currencies AS c, currency_conversions AS cc, ip_addresses AS ip
    WHERE sslc.fee_id = f.id
      AND f.currency_id = c.id
      AND c.id = cc.currency_id
      AND sslc.ip_id = ip.id
      AND sslc.active NOT IN ('0')
      AND cc.user_id = '" . $_SESSION['s_user_id'] . "'" .
      $range_string)->fetchAll();

foreach ($result_grand_total as $row_grand_total) {

    $grand_total = $row_grand_total->grand_total;
    $number_of_certs_total = $row_grand_total->number_of_certs_total;

}

$grand_total = $currency->format($grand_total, $_SESSION['s_default_currency_symbol'],
    $_SESSION['s_default_currency_symbol_order'], $_SESSION['s_default_currency_symbol_space']);

if ($submission_failed != '1' && $total_rows > 0) {

    if ($export_data == '1') {

        $export = new DomainMOD\Export();

        if ($daterange == '') {

            $export_file = $export->openFile('ssl_cost_by_ip_address_report_all', strtotime($time->stamp()));

        } else {

            $export_file = $export->openFile(
                'ssl_cost_by_ip_address_report',
                $new_start_date . '--' . $new_end_date
            );

        }

        $row_contents = array($page_title);
        $export->writeRow($export_file, $row_contents);

        $export->writeBlankRow($export_file);

        if ($daterange == '') {

            $row_contents = array('Date Range:', 'ALL');

        } else {

            $row_contents = array('Date Range:', $daterange);

        }
        $export->writeRow($export_file, $row_contents);

        $row_contents = array(
            'Total Cost:',
            $grand_total,
            $_SESSION['s_default_currency']
        );
        $export->writeRow($export_file, $row_contents);

        $row_contents = array(
            'Number of SSL Certs:',
            $number_of_certs_total
        );
        $export->writeRow($export_file, $row_contents);

        $export->writeBlankRow($export_file);

        $row_contents = array(
            'IP Address Name',
            'IP Address',
            'rDNS',
            'Cost',
            'SSL Certs',
            'Per Cert'
        );
        $export->writeRow($export_file, $row_contents);

        if ($result) {

            foreach ($result as $row) {

                $per_cert = $row->total_cost / $row->number_of_certs;

                $per_cert = $currency->format($per_cert, $_SESSION['s_default_currency_symbol'], $_SESSION['s_default_currency_symbol_order'], $_SESSION['s_default_currency_symbol_space']);

                $row->total_cost = $currency->format($row->total_cost, $_SESSION['s_default_currency_symbol'], $_SESSION['s_default_currency_symbol_order'], $_SESSION['s_default_currency_symbol_space']);

                $row_contents = array(
                    $row->name,
                    $row->ip,
                    $row->rdns,
                    $row->total_cost,
                    $row->number_of_certs,
                    $per_cert
                );
                $export->writeRow($export_file, $row_contents);

            }

        }
        $export->closeFile($export_file);

    }

} else {

    $total_rows = '0';

}
?>
<?php require_once DIR_INC . '/doctype.inc.php'; ?>
<html>
<head>
    <title><?php echo $system->pageTitle($page_title); ?></title>
    <?php require_once DIR_INC . '/layout/head-tags.inc.php'; ?>
    <?php require_once DIR_INC . '/layout/date-range-picker-head.inc.php'; ?>
</head>
<body class="hold-transition skin-red sidebar-mini">
<?php require_once DIR_INC . '/layout/header.inc.php'; ?>
<?php require_once DIR_INC . '/layout/reporting-block.inc.php'; ?>
<?php
if ($submission_failed != '1' && $total_rows > 0) { ?>

    <?php require_once DIR_INC . '/layout/reporting-block-sub.inc.php'; ?>

    <table id="<?php echo $slug; ?>" class="<?php echo $datatable_class; ?>">
        <thead>
        <tr>
            <th width="20px"></th>
            <th>Name</th>
            <th>IP Address</th>
            <th>rDNS</th>
            <th>Cost</th>
            <th>SSL Certs</th>
            <th>Per Cert</th>
        </tr>
        </thead>
        <tbody><?php

        foreach ($result as $row) {

            $per_cert = $row->total_cost / $row->number_of_certs;

            $per_cert = $currency->format($per_cert, $_SESSION['s_default_currency_symbol'], $_SESSION['s_default_currency_symbol_order'], $_SESSION['s_default_currency_symbol_space']);

            $row->total_cost = $currency->format($row->total_cost, $_SESSION['s_default_currency_symbol'], $_SESSION['s_default_currency_symbol_order'], $_SESSION['s_default_currency_symbol_space']); ?>

            <tr>
                <td></td>
                <td><?php echo $row->name; ?></td>
                <td><?php echo $row->ip; ?></td>
                <td><?php echo $row->rdns; ?></td>
                <td><?php echo $row->total_cost; ?></td>
                <td><a href="../../ssl/index.php?sslipid=<?php echo $row->id; ?>"><?php echo $row->number_of_certs; ?></a></td>
                <td><?php echo $per_cert; ?></td>
            </tr><?php

        } ?>

        </tbody>
    </table><?php

} else {

    echo 'No results.<BR><BR>';

}
?>
<?php require_once DIR_INC . '/layout/footer.inc.php'; //@formatter:on ?>
<?php require_once DIR_INC . '/layout/date-range-picker-footer.inc.php'; ?>
</body>
</html>
