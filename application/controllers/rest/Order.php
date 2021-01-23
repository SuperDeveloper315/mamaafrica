<?php defined('BASEPATH') or exit('No direct script access allowed');

// This can be removed if you use __autoload() in config.php OR use Modular Extensions
/** @noinspection PhpIncludeInspection */
require APPPATH . '/libraries/REST_Controller.php';
class Order extends REST_Controller
{
    public function __construct()
    {
        // Construct the parent class
        parent::__construct();

        // Configure limits on our controller methods
        // Ensure you have created the 'limits' table and enabled 'limits' within application/config/rest.php
        //$this->methods['users_get']['limit'] = 500; // 500 requests per hour per user/key
        //$this->methods['users_post']['limit'] = 100; // 100 requests per hour per user/key
        //$this->methods['users_delete']['limit'] = 50; // 50 requests per hour per user/key
        $this->load->model("orders_model");
        $this->load->library('Pdf');
        // include APPPATH.'/third_party/fpdf/html2fpdf.php';
    }
    public function list_post()
    {
        $user_id = $this->post("user_id");
        if ($user_id == null) {
            $this->response(array(
                RESPONCE => false,
                MESSAGE => _l("User referance required"),
                DATA =>_l("User referance required"),
                CODE => CODE_MISSING_INPUT
            ), REST_Controller::HTTP_OK);
        }
        
        $orders = $this->orders_model->get(array("orders.user_id"=>$user_id));
        $this->response(array(
                RESPONCE => true,
                MESSAGE => _l("You orders"),
                DATA => $orders,
                CODE => CODE_SUCCESS
            ), REST_Controller::HTTP_OK);
    }
    public function send_post()
    {
        $post = $this->post();
        $this->load->library('form_validation');
        $this->form_validation->set_rules('user_id', 'User Referance', 'trim|required');
        $this->form_validation->set_rules('paid_by', 'Payment Type', 'trim|required');
        $this->form_validation->set_rules('order_type', 'Order Type', 'trim|required');
        if ($this->form_validation->run() == false) {
            $this->response(array(
                        RESPONCE => false,
                        MESSAGE => strip_tags($this->form_validation->error_string()),
                        DATA =>strip_tags($this->form_validation->error_string()),
                        CODE => CODE_MISSING_INPUT
            ), REST_Controller::HTTP_OK);
        } else {
            $user_id = $post["user_id"];
            $paid_by = $post["paid_by"];
            $order_type = $post["order_type"];

            $branch_id = (isset($post["branch_id"])) ? $post["branch_id"] : "0";
            $user_address_id = (isset($post["user_address_id"])) ? $post["user_address_id"] : "0";
            $coupon_code = $post["coupon_code"];
            $order_note = $post["order_note"];

            $order_date = date(MYSQL_DATE_FORMATE);
                        
            $this->db->select("Max(order_id) as max_id");
            $q = $this->db->get("orders");
            $max_order = $q->row();
            $order_no = $max_order->max_id + 1;

            $this->load->model("cart_model");
            $cart = $this->cart_model->manage_cart($user_id);

            $order_products= $cart["products"];

            if ($order_products == null || empty($order_products)) {
                $this->response(array(
                    RESPONCE => false,
                    MESSAGE => _l("Something wrong in item inputs"),
                    DATA =>_l("Something wrong in item inputs"),
                    CODE => 100
                ), REST_Controller::HTTP_OK);
            }

            // Add Order Items
            // items are in json array [{product_id : 1, order_qty : 1, }]
            $this->load->model("products_model");
                        
            $net_amount = $cart["net_paid_amount"];
            $total_order_amount = $cart["total_amount"];
            $final_discount = $total_order_amount - $net_amount;
            // Validate Coupon First
            $coupon_responce = array();
            if ($coupon_code != null || $coupon_code != "") {
                $this->load->model("coupons_model");
                $coupon_responce = $this->coupons_model->validate($user_id, $coupon_code);
                if (!$coupon_responce[RESPONCE]) {
                    $this->response($coupon_responce, REST_Controller::HTTP_OK);
                }
            }

            // Applu Coupon on Total Amount if applicable
            
            $order_discount = 0;
            $order_discount_type = "";
            $order_discount_amount = 0;
            if (!empty($coupon_responce) && $coupon_responce[RESPONCE]) {
                $coupon = (Object)$coupon_responce[DATA];
                if (!empty($coupon)) {
                    if ($total_order_amount < $coupon->min_order_amount) {
                        $this->response(array(
                            RESPONCE => false,
                            MESSAGE => _l("Discount coupon is not applicable, Please try with min order amount ".$coupon->min_order_amount),
                            DATA =>_l("Discount coupon is not applicable, Please try with min order amount ".$coupon->min_order_amount),
                            CODE => 101
                        ), REST_Controller::HTTP_OK);
                    } else {
                        if ($coupon->discount_type == "flat") {
                            $order_discount_amount = $coupon->discount;
                        } elseif ($coupon->discount_type == "percentage") {
                            $order_discount_amount = $coupon->discount * $net_amount  / 100;
                        }
                        if ($order_discount_amount > $coupon->max_discount_amount) {
                            $order_discount_amount = $coupon->max_discount_amount;
                        }
                        $net_amount = $net_amount - $order_discount_amount;
                        $order_discount_type = $coupon->discount_type;
                        $order_discount = $coupon->discount;
                    }
                }
            }
            // Initial order insert
            $order_status = ORDER_PENDING;
            $gateway_charges = 0;
            if ($paid_by != "cod") {
                $order_status = ORDER_UNPAID;
                $gateway_charges = get_option("gateway_charges");
                
            }
            $delivery_charges = 0;
            $site_options = get_options(array("delivery_charge","currency_symbol"));
            if ($user_address_id != "0" && $order_type == "delivery") {
                $delivery_charges = $site_options["delivery_charge"];
            }

            $net_amount = $net_amount + $gateway_charges + $delivery_charges;
            $order_discount_amount = $order_discount_amount + $final_discount;
            $order_date_modified = date(MYSQL_DATE_TIME_FORMATE);
            
            $order_init = array(
                "order_no"=>$order_no,
                "order_date"=>$order_date_modified,
                "user_id"=>$user_id,
                "branch_id"=>$branch_id,
                "user_address_id"=>$user_address_id,
                "order_note"=>$order_note,
                "coupon_code"=>$coupon_code,
                "discount"=>$order_discount,
                "discount_type"=>$order_discount_type,
                "discount_amount"=>$order_discount_amount,
                "order_amount"=>$total_order_amount,
                "net_amount"=>$net_amount,
                "status"=>$order_status,
                "paid_by"=>$paid_by,
                "gateway_charges"=>$gateway_charges,
                "delivery_amount"=>$delivery_charges,
                "order_type"=>$order_type
            );
            $order_id = $this->common_model->data_insert("orders", $order_init, true);
            
            $this->common_model->data_insert("order_status", array("status"=>$order_status,"order_id"=>$order_id), true);

            foreach ($order_products as $item) {
                $order_item = array(
                    "order_id"=>$order_id,
                    "product_id"=>$item->product_id,
                    "order_qty"=>$item->qty,
                    "product_price"=>$item->price,
                    "discount_id"=>($item->product_discount_id == null) ? 0 : $item->product_discount_id,
                    "discount_amount"=>($item->discount_amount == null) ? 0 : $item->discount_amount,
                    "discount"=>($item->discount == null) ? 0 : $item->discount,
                    "discount_type"=>($item->discount_type == null) ? "" : $item->discount_type,
                    "price"=>$item->effected_price
                );
                $order_item_id = $this->common_model->data_insert("order_items", $order_item, false, false);
                foreach ($item->product_options as $option) {
                    $option_array = array(
                        "order_item_id"=>$order_item_id,
                        "order_id"=>$order_id,
                        "product_id"=>$item->product_id,
                        "product_option_id"=>$option->product_option_id,
                        "order_qty"=>$option->qty,
                        "option_price"=>$option->price,
                        "price"=>$option->price
                    );
                    $this->common_model->data_insert("order_item_options", $option_array, false, false);
                }
            }
            
            
            $setting = get_options_by_type("printing");
            if(_get_post_back($setting,'print_auto')=='auto'){
                $api_key = _get_post_back($setting,'printnode_api_key');
                
                //generate HTML CODE
                $this->db->from('orders');
                $this->db->join('users', 'users.user_id=orders.user_id', 'left');
                $this->db->join('order_items', 'order_items.order_id=orders.order_id', 'left');
                $this->db->join('products', 'products.product_id=order_items.product_id', 'left');
                $this->db->where('orders.order_id', $order_id);
                $query_print = $this->db->get();
                $fetch_data = $query_print->result();
                $html_str = "<!doctype html>
                <html>
                <head>
                    <meta charset='utf-8'>
                    <title>A simple HTML invoice template</title>
                    <style>
                    .invoice-box {
                        max-width: 800px;
                        margin: auto;
                        padding: 30px;
                        border: 1px solid #eee;
                        box-shadow: 0 0 10px rgba(0, 0, 0, .15);
                        font-size: 16px;
                        line-height: 24px;
                        font-family: 'Helvetica Neue', 'Helvetica', Helvetica, Arial, sans-serif;
                        color: #555;
                    }
                    .invoice-box table {
                        width: 100%;
                        line-height: inherit;
                        text-align: left;
                    }
                    .invoice-box table td {
                        padding: 5px;
                        vertical-align: top;
                    }
                    .invoice-box table tr td:nth-child(3) {
                        text-align: right;
                    }
                    .invoice-box table tr.top table td {
                        padding-bottom: 20px;
                    }
                    .invoice-box table tr.top table td.title {
                        font-size: 45px;
                        line-height: 45px;
                        color: #333;
                    }
                    .invoice-box table tr.information table td {
                        padding-bottom: 40px;
                    }
                    .invoice-box table tr.heading td {
                        background: #eee;
                        border-bottom: 1px solid #ddd;
                        font-weight: bold;
                    }
                    .invoice-box table tr.details td {
                        padding-bottom: 20px;
                    }
                    .invoice-box table tr.item td{
                        border-bottom: 1px solid #eee;
                    }
                    .invoice-box table tr.item.last td {
                        border-bottom: none;
                    }
                    .invoice-box table tr.total td:nth-child(2) {
                        border-top: 2px solid #eee;
                        font-weight: bold;
                    }
                    @media only screen and (max-width: 600px) {
                        .invoice-box table tr.top table td {
                            width: 100%;
                            display: block;
                            text-align: center;
                        }
                        .invoice-box table tr.information table td {
                            width: 100%;
                            display: block;
                            text-align: center;
                        }
                    }
                    .rtl {
                        direction: rtl;
                        font-family: Tahoma, 'Helvetica Neue', 'Helvetica', Helvetica, Arial, sans-serif;
                    }
                    .rtl table {
                        text-align: right;
                    }
                    .rtl table tr td:nth-child(2) {
                        text-align: left;
                    }
                    </style>
                </head>
                <body>
                    <div class='invoice-box'>";

                $table_str = "<table cellpadding='0' cellspacing='0'><tr class='top'><td colspan='2'><table><tr><td class='title'><img src=\"data:image/jpeg;base64,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b2wAAAAAAFJnc01ib29sAAAAAABDcm5DYm9vbAAAAAAAQ250Q2Jvb2wAAAAAAExibHNib29sAAAAAABOZ3R2Ym9vbAAAAAAARW1sRGJvb2wAAAAAAEludHJib29sAAAAAABCY2tnT2JqYwAAAAEAAAAAAABSR0JDAAAAAwAAAABSZCAgZG91YkBv4AAAAAAAAAAAAEdybiBkb3ViQG/gAAAAAAAAAAAAQmwgIGRvdWJAb+AAAAAAAAAAAABCcmRUVW50RiNSbHQAAAAAAAAAAAAAAABCbGQgVW50RiNSbHQAAAAAAAAAAAAAAABSc2x0VW50RiNQeGxAUgAAAAAAAAAAAAp2ZWN0b3JEYXRhYm9vbAEAAAAAUGdQc2VudW0AAAAAUGdQcwAAAABQZ1BDAAAAAExlZnRVbnRGI1JsdAAAAAAAAAAAAAAAAFRvcCBVbnRGI1JsdAAAAAAAAAAAAAAAAFNjbCBVbnRGI1ByY0BZAAAAAAAAAAAAEGNyb3BXaGVuUHJpbnRpbmdib29sAAAAAA5jcm9wUmVjdEJvdHRvbWxvbmcAAAAAAAAADGNyb3BSZWN0TGVmdGxvbmcAAAAAAAAADWNyb3BSZWN0UmlnaHRsb25nAAAAAAAAAAtjcm9wUmVjdFRvcGxvbmcAAAAAADhCSU0D7QAAAAAAEABIAAAAAQABAEgAAAABAAE4QklNBCYAAAAAAA4AAAAAAAAAAAAAP4AAADhCSU0D8gAAAAAACgAA////////AAA4QklNBA0AAAAAAAQAAAAeOEJJTQQZAAAAAAAEAAAAHjhCSU0D8wAAAAAACQAAAAAAAAAAAQA4QklNJxAAAAAAAAoAAQAAAAAAAAABOEJJTQP1AAAAAABIAC9mZgABAGxmZgAGAAAAAAABAC9mZgABAKGZmgAGAAAAAAABADIAAAABAFoAAAAGAAAAAAABADUAAAABAC0AAAAGAAAAAAABOEJJTQP4AAAAAABwAAD/////////////////////////////A+gAAAAA/////////////////////////////wPoAAAAAP////////////////////////////8D6AAAAAD/////////////////////////////A+gAADhCSU0ECAAAAAAAEAAAAAEAAAJAAAACQAAAAAA4QklNBB4AAAAAAAQA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OjQ4LTA4OjAwIiB4bXA6TW9kaWZ5RGF0ZT0iMjAyMS0wMS0yM1QwMzoyMjo0Ni0wODowMCIgeG1wOk1ldGFkYXRhRGF0ZT0iMjAyMS0wMS0yM1QwMzoyMjo0Ni0wODowMCIgZGM6Zm9ybWF0PSJpbWFnZS9qcGVnIiBwaG90b3Nob3A6TGVnYWN5SVBUQ0RpZ2VzdD0iMDAwMDAwMDAwMDAwMDAwMDAwMDAwMDAwMDAwMDAwMDEiIHBob3Rvc2hvcDpDb2xvck1vZGU9IjMiIHBob3Rvc2hvcDpJQ0NQcm9maWxlPSJzUkdCIElFQzYxOTY2LTIuMSIgeG1wTU06SW5zdGFuY2VJRD0ieG1wLmlpZDphMmEzYmE4Ni03YmFlLWUzNDgtYjRkZi0zZjQ1YTJmYjE4MmEiIHhtcE1NOkRvY3VtZW50SUQ9ImFkb2JlOmRvY2lkOnBob3Rvc2hvcDoyYjdjMzgzNS0wNjYwLWNkNGYtYTVmZC1iMzI4ODE4NzkxOTIiIHhtcE1NOk9yaWdpbmFsRG9jdW1lbnRJRD0ieG1wLmRpZDplYmYwMTYwZC1hNmJiLTBlNDQtOTkxMi03YWQ1MDkzZjU2YjciPiA8eG1wTU06SGlzdG9yeT4gPHJkZjpTZXE+IDxyZGY6bGkgc3RFdnQ6YWN0aW9uPSJjcmVhdGVkIiBzdEV2dDppbnN0YW5jZUlEPSJ4bXAuaWlkOmViZjAxNjBkLWE2YmItMGU0NC05OTEyLTdhZDUwOTNmNTZiNyIgc3RFdnQ6d2hlbj0iMjAyMS0wMS0yMlQxNToyNjo0OC0wODowMCIgc3RFdnQ6c29mdHdhcmVBZ2VudD0iQWRvYmUgUGhvdG9zaG9wIDIxLjAgKFdpbmRvd3MpIi8+IDxyZGY6bGkgc3RFdnQ6YWN0aW9uPSJjb252ZXJ0ZWQiIHN0RXZ0OnBhcmFtZXRlcnM9ImZyb20gaW1hZ2UvcG5nIHRvIGltYWdlL2pwZWciLz4gPHJkZjpsaSBzdEV2dDphY3Rpb249InNhdmVkIiBzdEV2dDppbnN0YW5jZUlEPSJ4bXAuaWlkOjcyN2JhNzhjLWIxODctMWE0MC1hM2MyLWQ0MGZhN2M5MTkzMyIgc3RFdnQ6d2hlbj0iMjAyMS0wMS0yMlQxNjo1NDo0Ny0wODowMCIgc3RFdnQ6c29mdHdhcmVBZ2VudD0iQWRvYmUgUGhvdG9zaG9wIDIxLjAgKFdpbmRvd3MpIiBzdEV2dDpjaGFuZ2VkPSIvIi8+IDxyZGY6bGkgc3RFdnQ6YWN0aW9uPSJzYXZlZCIgc3RFdnQ6aW5zdGFuY2VJRD0ieG1wLmlpZDphMmEzYmE4Ni03YmFlLWUzNDgtYjRkZi0zZjQ1YTJmYjE4MmEiIHN0RXZ0OndoZW49IjIwMjEtMDEtMjNUMDM6MjI6NDYtMDg6MDAiIHN0RXZ0OnNvZnR3YXJlQWdlbnQ9IkFkb2JlIFBob3Rvc2hvcCAyMS4wIChXaW5kb3dzKSIgc3RFdnQ6Y2hhbmdlZD0iLyIvPiA8L3JkZjpTZXE+IDwveG1wTU06SGlzdG9yeT4gPC9yZGY6RGVzY3JpcHRpb24+IDwvcmRmOlJERj4gPC94OnhtcG1ldGE+ICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgPD94cGFja2V0IGVuZD0idyI/Pv/iDFhJQ0NfUFJPRklMRQABAQAADEhMaW5vAhAAAG1udHJSR0IgWFlaIAfOAAIACQAGADEAAGFjc3BNU0ZUAAAAAElFQyBzUkdCAAAAAAAAAAAAAAABAAD21gABAAAAANMtSFAgIAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAEWNwcnQAAAFQAAAAM2Rlc2MAAAGEAAAAbHd0cHQAAAHwAAAAFGJrcHQAAAIEAAAAFHJYWVoAAAIYAAAAFGdYWVoAAAIsAAAAFGJYWVoAAAJAAAAAFGRtbmQAAAJUAAAAcGRtZGQAAALEAAAAiHZ1ZWQAAANMAAAAhnZpZXcAAAPUAAAAJGx1bWkAAAP4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\" width='100'></td><td><br><br><br>Order Number: ".$order_id."<br>Created: ".$order_date_modified."<br></td></tr></table></td></tr><br><br><tr class='information'><td colspan='2'><table><tr><td>Mama Africa Takeaway<br>27 Farthing Grove<br>Netherfield, MK6 4JH</td><td>".$fetch_data[0]->user_firstname." ".$fetch_data[0]->user_lastname."<br>".$fetch_data[0]->user_phone."<br>".$fetch_data[0]->user_email."</td></tr></table></td></tr></table><br><br><br><table style='margin-top:20px'><tr class='heading' style='background-color:#aaaaaa'><th width=\"70%\" align=\"center\"><strong>Item</strong></th><th width=\"10%\" align=\"center\"><strong>Price</strong></th><th width=\"10%\" align=\"center\"><strong>Qty</strong></th><th style=\"10%\" align=\"center\"><strong>Amount</strong></th></tr><hr>";
                $tr_str = "";
                $pay_amt = 0;
                $pay_amt_per = 0;
                foreach($fetch_data as $each){
                    $pay_amt_per = number_format($each->order_qty)*number_format($each->price, 2);
                    $tr_str .= "<tr class='item'><td align=\"center\">".$each->product_name_en."</td><td align=\"center\">".$each->price."</td><td align=\"center\">".$each->order_qty."</td><td align=\"center\">".$pay_amt_per."</td></tr><hr>";
                    $pay_amt += number_format($each->order_qty)*number_format($each->price, 2);
                }
                $table_str = $table_str.$tr_str."<tr class='total'><td></td><td></td><td align=\"center\"><strong>Total:</strong></td>
                            <td align=\"center\">".$pay_amt."</td></tr></table><hr><br><br><br><br><table><tr> <td><div style='text-align: center;color:red' align=\"center\"><strong> THANK YOU FOR YOUR CUSTOM. PLEASE COME AGAIN SOON</strong> </div></td></tr></table>";
                $html_str = $html_str.$table_str."</div></body></html>";
                $this->print_content($api_key, $html_str);
            }
            if ($paid_by == "cod") {
                // Flush Cart
                $this->order_response($order_id, "", TRUE);
            } else {
                $this->load->library("payment_lib");
                $pay_ref = $this->payment_lib->doPayment($order_id, $net_amount);
                if ($pay_ref["response"]) {
                    $this->db->update("orders", array("payment_ref"=>$pay_ref["payment_ref"]), array("order_id"=>$order_id));
                    $this->response(array(
                        RESPONCE => true,
                        MESSAGE => $html_str, //$pay_ref["redirect_url"],
                        DATA => array("responseURL"=>$pay_ref["redirect_url"]),
                        CODE => CODE_SUCCESS
                    ), REST_Controller::HTTP_OK);
                } else {
                    $this->db->where("order_id", $order_id);
                    $this->db->delete("orders");

                    $this->db->where("order_id", $order_id);
                    $this->db->delete("order_items");

                    $this->db->where("order_id", $order_id);
                    $this->db->delete("order_delivery_address");

                    $this->response(array(
                        RESPONCE => false,
                        MESSAGE => _l("Sorry failed to make payment"),
                        DATA =>_l("Sorry failed to make payment"),
                        CODE => 101
                    ), REST_Controller::HTTP_OK);
                }
            }
        }
    }
    public function failedpayment_get()
    {
        $token = $this->get("token");
        if ($token == null) {
            $this->response(array(
                RESPONCE => false,
                MESSAGE => _l("Sorry failed to get payment token"),
                DATA =>_l("Sorry failed to get payment token"),
                CODE => 101
            ), REST_Controller::HTTP_OK);
        }
        $order = $this->orders_model->get_by_id("", $token);
        $order_id = $order->order_id;
        $this->db->where("order_id", $order_id);
        $this->db->delete("orders");

        $this->db->where("order_id", $order_id);
        $this->db->delete("order_items");

        $this->db->where("order_id", $order_id);
        $this->db->delete("order_delivery_address");

        $this->response(array(
                        RESPONCE => false,
                        MESSAGE => _l("Sorry failed to make payment"),
                        DATA =>_l("Sorry failed to make payment"),
                        CODE => 101
                    ), REST_Controller::HTTP_OK);
    }
    public function successpayment_get()
    {
        $token = $this->get("token");
        if ($token == null) {
            $this->response(array(
                RESPONCE => false,
                MESSAGE => _l("Sorry failed to get payment token"),
                DATA =>_l("Sorry failed to get payment token"),
                CODE => 101
            ), REST_Controller::HTTP_OK);
        }
        $this->order_response("", $token);
    }
    private function order_response($order_id, $pay_ref="", $send_flag=FALSE)
    {
        $order = $this->orders_model->get_by_id($order_id, $pay_ref);
        $order_items = $this->orders_model->get_order_items($order->order_id);
        $order->items = $order_items;
        $user_id = $order->user_id;

        $this->db->where("user_id", $user_id);
        $this->db->delete("cart");

        $this->db->where("user_id", $user_id);
        $this->db->delete("cart_option");
            
        $this->load->model("email_model");
         
        foreach ($order_items as $item) {
            $option_items = $this->orders_model->get_order_option_items($item->order_item_id);
            $item->option_items = $option_items;
        }
        $this->email_model->send_order_mail($order, $order_items);
        $this->email_model->new_order_mail($order, $order_items);
            
        $msg = _l("Thanks for your order, Order with No #order_no# amount #net_amount# is placed successfully");
        $msg = str_replace(array("#order_no#","#net_amount#"), array($order->order_no,$order->net_amount), $msg);
        $this->response(array(
                    RESPONCE => true,
                    MESSAGE => $msg,
                    DATA => $order,
                    CODE => CODE_SUCCESS
                ), REST_Controller::HTTP_OK, FALSE, $send_flag);
    }
    public function track_post()
    {
        $user_id = $this->post("user_id");
        if ($user_id == null) {
            $this->response(array(
                RESPONCE => false,
                MESSAGE => _l("Please Provide User Referance"),
                DATA =>_l("Please Provide User Referance"),
                CODE => 100
            ), REST_Controller::HTTP_OK);
        }
        $order = $this->orders_model->get(array("orders.user_id"=>$user_id, "in"=>array("orders.status"=>array(
            ORDER_PENDING, ORDER_CONFIRMED, ORDER_OUT_OF_DELIVEY, ORDER_DELIVERED
        )),"DATE(orders.order_date) >="=>date(MYSQL_DATE_FORMATE)));
        foreach ($order as $o) {
            $o->order_status = $this->orders_model->get_status($o->order_id);
        }
        $this->response(array(
            RESPONCE => true,
            MESSAGE => _l("Track Orders"),
            DATA => $order,
            CODE => CODE_SUCCESS
        ), REST_Controller::HTTP_OK);
    }
    public function details_post()
    {
        $order_id = $this->post("order_id");
        $user_id = $this->post("user_id");
        if ($order_id == null || $user_id == null) {
            $this->response(array(
                RESPONCE => false,
                MESSAGE => _l("Please Provide Order Referance"),
                DATA =>_l("Please Provide Order Referance"),
                CODE => 100
            ), REST_Controller::HTTP_OK);
        }
        $order = $this->orders_model->get_by_id($order_id);
        $order_items = $this->orders_model->get_order_items($order_id);
        $order->items = $order_items;
        $order->order_status = $this->orders_model->get_status($order_id);
        $this->response(array(
            RESPONCE => true,
            MESSAGE => _l("Order Details"),
            DATA => $order,
            CODE => CODE_SUCCESS
        ), REST_Controller::HTTP_OK);
    }
    public function cancel_post()
    {
        $order_id = $this->post("order_id");
        $user_id = $this->post("user_id");
        if ($order_id == null || $user_id == null) {
            $this->response(array(
                RESPONCE => false,
                MESSAGE => _l("Please Provide Order Referance"),
                DATA =>_l("Please Provide Order Referance"),
                CODE => 100
            ), REST_Controller::HTTP_OK);
        }
        $order = $this->orders_model->get_by_id($order_id);
        if ($order->status != ORDER_PENDING) {
            $this->response(array(
                RESPONCE => false,
                MESSAGE => _l("Sorry we can not cancel order, Because order in processing."),
                DATA =>_l("Sorry we can not cancel order, Because order in processing."),
                CODE => 100
            ), REST_Controller::HTTP_OK);
        }
        $this->common_model->data_update("orders", array("status"=>ORDER_CANCEL), array("order_id"=>$order_id));

        $this->response(array(
            RESPONCE => true,
            MESSAGE => _l("Order Details"),
            DATA => $order,
            CODE => CODE_SUCCESS
        ), REST_Controller::HTTP_OK);
    }

    public function print_content($api_key, $content)
    {
        // $tcpdf = new Pdf(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
        $tcpdf = new Pdf('P','mm','A4',true,'UTF-8',false);
        $tcpdf->SetDefaultMonospacedFont(PDF_FONT_MONOSPACED);
        $tcpdf->SetTitle('Bill Collection Letter');
        $tcpdf->SetMargins(10,10,10,10);
        $tcpdf->SetHeaderMargin(PDF_MARGIN_HEADER);
        $tcpdf->SetFooterMargin(PDF_MARGIN_FOOTER);
        $tcpdf->setPrintHeader(FALSE);
        $tcpdf->setPrintFooter(FALSE);
        $tcpdf->setListIndentWidth(3);
        $tcpdf->SetAutoPageBreak(TRUE,11);
        $tcpdf->setImageScale(PDF_IMAGE_SCALE_RATIO);
        $tcpdf->AddPage();
        $tcpdf->SetFont('times','',10.5);
        $tcpdf->writeHTML($content,TRUE,FALSE,FALSE,FALSE,'');
        ob_end_clean();
        $pdf_content = $tcpdf->Output('demo.pdf','S');
        $apiurl = "https://api.printnode.com";
        $api_key = base64_encode($api_key);
        $ch = curl_init();

        //set the url, number of POST vars, POST data
        curl_setopt($ch,CURLOPT_URL, $apiurl.'/computers');
        curl_setopt($ch,CURLOPT_HTTPHEADER,array('Authorization:Basic '.$api_key));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);

        //execute post
        $computers = curl_exec($ch);
        $computers = json_decode($computers);
        $printers = [];
        if(is_array($computers)) {
            foreach ($computers as $each_computer) {
                curl_setopt($ch, CURLOPT_URL, $apiurl.'/computers/'.$each_computer->id.'/printers');
                $each_printers = curl_exec($ch);
                $each_printers = json_decode($each_printers);
                array_push($printers, $each_printers[0]);
            }
        }
        curl_setopt($ch, CURLOPT_POST, TRUE);
        curl_setopt($ch,CURLOPT_HTTPHEADER,array('Authorization:Basic '.$api_key,"Content-Type:application/json"));
        curl_setopt($ch, CURLOPT_URL, $apiurl.'/printjobs');
        foreach ($printers as $each_printer) {
            $data = ['printer' => $each_printer, 'title' => "mamaafrica", 'contentType' => "pdf_base64", 'content' => base64_encode($pdf_content), 'source' => "mamaafrica"];
            $body = json_encode($data);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
            $respond = curl_exec($ch);
        }
     }
}
