<?php
/**
 * Created by PhpStorm.
 * User: user
 * Date: 17-Oct-16
 * Time: 9:22 AM
 */
require_once "Users.php";

class Orders extends Users {

    protected $db_connection = null;
    private $errors = array();
    private $orders_table = ORDERS_TABLE;
    private $customers_table = USERS_TABLE;
    private $top_buy_table = TOP_BUYS_TABLE;
    private $top_sell_table = TOP_SELL_TABLE;
    private $customer_balance_table = CREDITS_TABLE;
    private $transaction_table = TRANSACTIONS_TABLE;
    private $bal_history = CREDITS_HISTORY_TABLE;
    private $bank_acc = ACCOUNTS_TABLE;
    private $fund_trans = TRANSFER_INFO_TABLE;
    private $customerId = 0;
    private $orderTypeId = 0;
    private $quantity = 0;
    private $demanded_qty = 0;
    private $price = 0;
    private $orderStatusId = 2; //pending
    private $max_top_bids = 20;
    private $customer_balance = null; // Don't make it 0
    private $customer_frozen_balance = null; // Don't make it 0

    private function insert_order_in_active_table($top_table, $orderId, $price, $quantity) {

        if ($this->databaseConnection()) {

            $n = new DateTime("now", new DateTimeZone("Asia/Kolkata"));
            $now = $n->format('Y-m-d H:i:s');

            $query = $this->db_connection->prepare("INSERT INTO $top_table(`price`, `orderId`, `quantity`, `customerId`, `insertDate`)
                      VALUES (:price, :orderId, :quantity, :user_id, '$now')");
            $query->bindParam("price", $price);
            $query->bindParam("orderId", $orderId);
            $query->bindParam("quantity", $quantity);
            $query->bindParam("user_id", $_SESSION['user_id']);

            if ($query->execute()) {
                $this->updateOrderStatus($orderId, 3);
                return true;
            }
            return false;
        }
        return false;
    }

    public function check_customer_balance($assetType, $user_id) {

        if ($this->databaseConnection()) {

            $customer_balance = null;
            $query = $this->db_connection->prepare("SELECT `Balance`, FrozenBalance
                                    FROM $this->customer_balance_table
                                    WHERE `CustomerId`= :user_id AND `AssetTypeId`='$assetType'");
            $query->bindParam(":user_id", $user_id);
            if ($query->execute()) {
                if ($query->rowCount()) {
                    $customer_balance = $query->fetchObject();
                }
            }
            return $customer_balance;
        }
        return false;
    }

    public function record_bal_history($user_id, $balance, $type) {
        if ($this->databaseConnection()) {

            $now = $this->time_now();

            $query = $this->db_connection->prepare("INSERT INTO $this->bal_history (`id`, `user_id`, `balance`, `AssetType`, `datetime`) 
                      VALUES ('', :uid, :bal, :asset_type, '$now')");
            $query->bindParam('uid', $user_id);
            $query->bindParam('bal', $balance);
            $query->bindParam('asset_type', $type);

            if ($query->execute()) {
                return true;
            }
        }
        return false;
    }

    public function update_user_balance($assetType, $balance=null, $user_id) {

        if ($this->databaseConnection()) {
            $now = $this->time_now();
            $sql = "";
            if ($balance >= 0) {
                $sql .= "UPDATE $this->customer_balance_table ";
                $sql .= " SET `Balance`= :balance, ";
                $sql .= " `UpdateDate`= '$now' ";
                $sql .= " WHERE `CustomerId`= :user_id ";
                $sql .= " AND `AssetTypeId`= :asset_type ";
                $sql .= "LIMIT 1";

                $query = $this->db_connection->prepare($sql);

                if ($balance >= 0) {
                    $query->bindParam("balance", $balance);
                }
                $query->bindParam("user_id", $user_id);
                $query->bindParam("asset_type", $assetType);
                if ($query->execute()) {
                    $this->record_bal_history($user_id, $balance, $assetType);
                    return true;
                }
            }
            return false;
        }
        return false;
    }

    public function insert_pending_order($orderTypeId, $qty, $price, $orderStatusId, $OfferAssetTypeId=null, $WantAssetTypeId=null) {

        if ($this->databaseConnection()) {
            $now = $this->time_now();
            $messages = null;

            $this->customerId = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : 0;
            $this->orderTypeId = $orderTypeId;  // 0-> buy; 1 -> sell;
            $this->quantity = $qty;
            $this->price = $price;
            $this->orderStatusId = $orderStatusId;  // 0 -> cancelled; 1 -> complete; 2 -> pending; 3 ->order active

            $std = new stdClass();
            $std->insertedrowid = null;
            $std->orderTypeId = null; // 0 -> buy; 1 -> sell
            $std->item_qty = null;
            $std->item_price = null;
            $std->orderStatusId = null;
            $std->insert_date = null;
            $std->error = true;
            $std->message = null;

            // check user balance
            $assetType = null;
            $total_trade_val = null;
            if ($this->orderTypeId == 0) {
                $assetType = 'traditional';
                $total_trade_val = $this->quantity * $this->price;
            } else if ($this->orderTypeId == 1) {
                $assetType = 'btc';
                $total_trade_val = $this->quantity;
            }

            $customer_bal = (float)$this->check_customer_balance($assetType, $this->customerId)->Balance;

            $this->customer_balance = $customer_bal;

            if ($this->customer_balance == '' || $this->customer_balance == null || !is_float($this->customer_balance)) {
                $messages = "0 balance: Your account balance is nill.";
                $std->message = $messages;
                $this->storeMessages($order_id=null, $this->customerId, $messages);
                return $std;
            }

            if ($total_trade_val > $this->customer_balance) {
                $messages = "Insufficient balance: You have insufficient balance to continue this trade. Please recharge your wallet or lower the quantity.";
                $std->message = $messages;
                $this->storeMessages($order_id=null, $this->customerId, $messages);
                return $std;
            }

            $query = $this->db_connection->prepare("INSERT INTO $this->orders_table (`OrderId`, `CustomerId`, `OrderTypeId`, `OfferAssetTypeId`, `WantAssetTypeId`, `Quantity`, `Price`, `OrderStatusId`, `UpdateDate`, `InsertDate`, `SaveDate`)
                                    VALUES ('', " . $this->customerId . ", :a, :e, :f, :b, :c, :d, NULL, '$now', NULL)");

            $query->bindParam(':a', $this->orderTypeId, PDO::PARAM_STR);
            $query->bindParam(':e', $OfferAssetTypeId, PDO::PARAM_STR);
            $query->bindParam(':f', $WantAssetTypeId, PDO::PARAM_STR);
            $query->bindParam(':b', $this->quantity, PDO::PARAM_STR);
            $query->bindParam(':c', $this->price, PDO::PARAM_STR);
            $query->bindParam(':d', $this->orderStatusId);

            if ($query->execute()) {

                $insertedrowid = $this->db_connection->lastInsertId();

                // Check if $price is eligible to be inserted into top_buy or top_sell table
                $top_table = null;
                $asc_desc = null;
                $new_balance = null;
                $new_frozenbalance = null;

                if ($orderTypeId == '0') {
                    $top_table = $this->top_buy_table;
                } else if ($orderTypeId == '1') {
                    $top_table = $this->top_sell_table;
                }

                $trade_type = ($this->orderTypeId==1) ? "sell" : "buy";
                $messages = "You entered a $trade_type order for $qty token at $ $price per token for $ ".$qty*$price;

                $this->storeMessages($insertedrowid, $this->customerId, $messages);

                // Change the order status to active and insert in active table in DB
                $insert_in_active_table = $this->insert_order_in_active_table($top_table, $insertedrowid, $this->price, $this->quantity);

                $this->orderStatusId = 3; // order activated

                $std = new stdClass();
                $std->insertedrowid = $insertedrowid;
                $std->made_to_active_list = $insert_in_active_table;
                $std->orderTypeId = $this->orderTypeId; // 0 -> buy; 1 -> sell
                $std->item_qty = $qty;
                $std->item_price = $price;
                $std->orderStatusId = $this->orderStatusId;
                $std->insert_date = date('Y-m-d H:i:s');
                $std->error = false;
                $std->message = "Order moved to active table.";

                return $std;
            }
            return null;
        }
        return false;
    }

    public function get_top_buy_sell_list($top_table, $asc_desc) {

        if ($this->databaseConnection()) {

            $top_list = array();

            $query = $this->db_connection->query("SELECT $top_table.OrderId, $top_table.customerId, $top_table.Quantity, $top_table.Price, ".USERS_TABLE.".Name
                                    FROM $top_table, ".USERS_TABLE."
                                    WHERE $top_table.customerId = ".USERS_TABLE.".CustomerId
                                    ORDER BY price $asc_desc
                                    LIMIT $this->max_top_bids
                                    ");

            if ($query) {

                $rowCount = $query->rowCount();

                if ($rowCount > 0) {

                    while ($orders = $query->fetchObject()) {

                        $top_list[] = $orders;

                    }
                }

            } else {
                return false;
            }
            return $top_list;
        }
        return false;
    }

    public function get_all_buy_sell_list($buy_or_sell_id, $AscDesc) {

        if ($this->databaseConnection()) {

            $buy_or_sell_list = array();

            $query = $this->db_connection->prepare("SELECT $this->orders_table.OrderId, $this->customers_table.CustomerId, $this->customers_table.Name, $this->orders_table.Quantity, $this->orders_table.Price, ($this->orders_table.Quantity * $this->orders_table.Price) AS TOTAL_COST,  $this->orders_table.OrderStatusid, $this->orders_table.InsertDate
                                    FROM $this->orders_table, $this->customers_table
                                    WHERE $this->orders_table.OrderTypeId = :id
                                    GROUP BY $this->orders_table.Price $AscDesc");

            $query->bindParam("id", $buy_or_sell_id);

            if ($query->execute()) {

                $rowCount = $query->rowCount();

                if ($rowCount > 0) {

                    while ($orders = $query->fetchObject()) {

                        $buy_or_sell_list[] = $orders;

                    }
                }

            } else {
                return false;
            }
            return $buy_or_sell_list;
        }
        return false;
    }

    public function get_active_order_of_user($user_id, $top_table) {
        if ($this->databaseConnection()) {
            $query = $this->db_connection->prepare("
                SELECT * FROM $top_table WHERE `customerId`= :uid ORDER BY `insertDate` DESC 
            ");
            $query->bindParam('uid', $user_id);
            $query->execute();

            $arr = array();
            while ($qr = $query->fetchObject()) {
                $arr[] = $qr;
            }
            return $arr;
        }
        return false;
    }

    public function OrderMatchingQuery() {

        if ($this->databaseConnection()) {

            $query = $this->db_connection->query("
                SELECT $this->top_sell_table.orderId, $this->top_sell_table.price, $this->top_sell_table.Quantity, $this->top_sell_table.orderId, (SELECT `CustomerId` FROM $this->top_buy_table ORDER BY price DESC LIMIT 1) AS BUYER_ID, $this->top_sell_table.CustomerId AS SELLER_ID
                FROM $this->top_sell_table, $this->orders_table
                WHERE (
                 ($this->top_sell_table.price <= (SELECT `price` FROM `$this->top_buy_table` ORDER BY price DESC LIMIT 1))
                 AND ($this->orders_table.OrderId = $this->top_sell_table.orderId)
                 AND ($this->orders_table.OrderStatusId = '3')
                 AND ($this->orders_table.OrderTypeId= '1') )
                ORDER BY $this->top_sell_table.price ASC
            ");

            if($rowCount = $query->rowCount() > 0) { // Transaction is possible
                $matched_orders = array();
                while ($obj = $query->fetchObject()) {
                    $matched_orders[] = $obj;
                }
                return $matched_orders;
            }
            return false;
        }
        return false;
    }

    private function get_highest_demand() {
        if ($this->databaseConnection()) {

            $query = $this->db_connection->query("SELECT $this->top_buy_table.OrderId, $this->top_buy_table.Price, $this->top_buy_table.Quantity FROM `$this->orders_table`, `$this->top_buy_table` WHERE $this->orders_table.OrderId = $this->top_buy_table.orderId ORDER BY $this->top_buy_table.price DESC LIMIT 1");
            $rowCount_Qty = $query->rowCount();
            if (!$rowCount_Qty) {
                return false;
            }
            return $highest_demanded = $query->fetchObject();
        }
        return false;
    }

    private function updateOrderStatus($orderId=null, $status=null) {
        if ($this->databaseConnection()) {
            $query = $this->db_connection->prepare("UPDATE ".ORDERS_TABLE." SET `OrderStatusId`= '$status' WHERE `OrderId` = :id LIMIT 1");
            $query->bindParam("id", $orderId);
            if ($query->execute()) {
                return true;
            }
        }
        return false;
    }

    public function OrderMatchingService() {

        if ($this->databaseConnection()) {

            $message = array();
            $trade_qty = 0;

            $this->demanded_qty = ($this->get_highest_demand() != false) ? $this->get_highest_demand()->Quantity : 0;
            $buy_order_id = ($this->get_highest_demand() != false) ? $this->get_highest_demand()->OrderId : 0;
            $buy_amount = ($this->get_highest_demand() != false) ? $this->get_highest_demand()->Price : 0;

            $supply_available = $this->OrderMatchingQuery();

            if ($this->demanded_qty == false || $this->demanded_qty == '' || $this->demanded_qty == '0' || $supply_available == false || $supply_available == '' || !is_array($supply_available) || empty($supply_available)) {
                return false;
            }

            $this->demanded_qty = (float)$this->demanded_qty;

            if(is_array($supply_available) && !empty($supply_available) ) {

                foreach ($supply_available as $available) {

                    if ($this->demanded_qty > 0) {
                        $supply_available = $this->OrderMatchingQuery();
                        $seller_order_id = (int)$available->orderId;
                        $available->Quantity = (float)$available->Quantity;
                        $seller_id = $available->SELLER_ID;
                        $seller_balance_btc = $this->check_customer_balance($assetType = 'btc', $seller_id)->Balance;
                        $seller_balance_cash = $this->check_customer_balance($assetType = 'traditional', $seller_id)->Balance;

                        $buyer_id = $available->BUYER_ID;
                        $buyer_balance_btc = $this->check_customer_balance($assetType = 'btc', $buyer_id)->Balance;
                        $buyer_balance_cash = $this->check_customer_balance($assetType = 'traditional', $buyer_id)->Balance;

                        if ($this->demanded_qty > $available->Quantity) {

                            $trade_qty = (float) $available->Quantity;

                            $cost_of_total_supply = $available->Quantity * $available->price; // traditional or cash
                            $cost_of_total_supply = (float) $cost_of_total_supply;

                            if ($buyer_balance_cash < $cost_of_total_supply) {
                                /*Record the message*/
                                $this->storeMessages($buy_order_id, $buyer_id, $msg="Transaction failed: You have insufficient cash balance.");
                                if ($_SESSION['user_id'] == $buyer_id) {
                                    $message[] = "Transaction failed: You have insufficient cash balance.";
                                }
                                // Delete the culprit order
                                $this->del_order($buy_order_id, $buyer_id);
                                break;
                            }
                            if (($seller_balance_btc == 0) || ($seller_balance_btc < $available->Quantity)) {
                                /*Record the message*/
                                $this->storeMessages($seller_order_id, $seller_id, $msg="Transaction failed: You had insufficient RMT balance");
                                if ($_SESSION['user_id'] == $seller_id) {
                                    $message[] = "Transaction failed: You had insufficient RMT balance";
                                }
                                $this->del_order($seller_order_id, $seller_id);
                                break;
                            }

                            if ($buyer_id != $seller_id) {
                                $new_seller_balance_cash = $seller_balance_cash + $cost_of_total_supply;  // traditional or cash
                                $new_seller_balance_btc = $seller_balance_btc - $available->Quantity; // deduct the btc sold

                                $new_buyer_balance_btc = $buyer_balance_btc + $available->Quantity; // btc
                                $new_buyer_balance_cash = $buyer_balance_cash - $cost_of_total_supply; // traditional or cash

                                // subtract the debit access (customers balance of $ or BTC)
                                $this->update_user_balance($assetType = 'btc', $balance = $new_buyer_balance_btc, $buyer_id);

                                // increment the credit asset (customers balance of $ or BTC)
                                $this->update_user_balance($assetType = 'traditional', $balance = $new_seller_balance_cash, $seller_id);

                                // record the commission in the commission account
                                // decrease respective balances
                                $this->update_user_balance($assetType = 'btc', $balance = $new_seller_balance_btc, $seller_id);
                                $this->update_user_balance($assetType = 'traditional', $balance = $new_buyer_balance_cash, $buyer_id);
                            }

                            $this->demanded_qty = $this->demanded_qty - $available->Quantity;

                            // update the quantity field for demand
                            $this->update_quantity($top_table = $this->top_buy_table, $this->demanded_qty, $buy_order_id);

                            // Delete the row from Sell list
                            $this->delete_order($this->top_sell_table, $available->orderId);

                            // Mark this order status 1 i.e transaction successful
                            $this->updateOrderStatus($available->orderId, $status='1');

                            // Record messages
                            $buyer_msg = "Transaction successful: You bought $available->Quantity RMT for $ $cost_of_total_supply at the rate of $ $available->price per token.";
                            $seller_msg = "Transaction successful: You sold $available->Quantity RMT for $ $cost_of_total_supply at the rate of $ $available->price per token.";
                            $this->storeMessages($buy_order_id, $buyer_id, $msg=$buyer_msg);
                            $this->storeMessages($seller_order_id, $seller_id, $msg=$seller_msg);

                            if(isset($_SESSION['user_id'])) {
                                $logged_in_user = (int) $_SESSION['user_id'];
                                if ($this->check_user($logged_in_user) != false) {
                                    if ($logged_in_user == $buyer_id) {
                                        $message[] = $buyer_msg;
                                    } else if($logged_in_user == $seller_id) {
                                        $message[] = $seller_msg;
                                    }
                                }
                            }

                            $available->Quantity = 0;

                        } elseif ($this->demanded_qty == $available->Quantity) {

                            $trade_qty = (float) $available->Quantity;

                            $cost_of_total_supply = $available->Quantity * $available->price; // traditional or cash
                            $cost_of_total_supply = (float) $cost_of_total_supply;

                            if ($buyer_balance_cash < $cost_of_total_supply) {
                                /*Record the message*/
                                $this->storeMessages($buy_order_id, $buyer_id, $msg="Transaction failed: You had insufficient cash balance.");
                                if ($_SESSION['user_id'] == $buyer_id) {
                                    $message[] = "Transaction failed: You have insufficient cash balance.";
                                }
                                $this->del_order($buy_order_id, $buyer_id);
                                break;
                            }
                            if (($seller_balance_btc == 0) || ($seller_balance_btc < $available->Quantity)) {
                                /*Record the message*/
                                $this->storeMessages($seller_order_id, $seller_id, $msg="Transaction failed: You had insufficient RMT balance.");
                                if ($_SESSION['user_id'] == $seller_id) {
                                    $message[] = "Transaction failed: You had insufficient RMT balance";
                                }
                                $this->del_order($seller_order_id, $seller_id);
                                break;
                            }

                            if ($buyer_id != $seller_id) {
                                $new_seller_balance_cash = $seller_balance_cash + $cost_of_total_supply;  // traditional or cash
                                $new_seller_balance_btc = $seller_balance_btc - $available->Quantity; // deduct the btc sold

                                $new_buyer_balance_btc = $buyer_balance_btc + $available->Quantity; // btc
                                $new_buyer_balance_cash = $buyer_balance_cash - $cost_of_total_supply; // traditional or cash

                                // subtract the debit access (customers balance of $ or BTC)
                                $this->update_user_balance($assetType = 'btc', $balance = $new_buyer_balance_btc, $user_id = $buyer_id);

                                // increment the credit asset (customers balance of $ or BTC)
                                $this->update_user_balance($assetType = 'traditional', $balance = $new_seller_balance_cash, $user_id = $seller_id);

                                // record the commission in the commission account
                                // decrease respective balances
                                $this->update_user_balance($assetType = 'btc', $balance = $new_seller_balance_btc, $user_id = $seller_id);
                                $this->update_user_balance($assetType = 'traditional', $balance = $new_buyer_balance_cash, $user_id = $buyer_id);
                            }

                            // Delete the row from Sell list And Buy list
                            $this->delete_order($this->top_sell_table, $available->orderId);
                            $this->delete_order($this->top_buy_table, $buy_order_id);

                            // Mark trades of buyer & seller 1 i.e 'successful'
                            $this->updateOrderStatus($available->orderId, $status='1');
                            $this->updateOrderStatus($buy_order_id, $status='1');

                            // Record messages
                            $buyer_msg = "Transaction successful: You bought $available->Quantity RMT for $ $cost_of_total_supply at the rate of $ $available->price per token.";
                            $seller_msg = "Transaction successful: You sold $available->Quantity RMT for $ $cost_of_total_supply at the rate of $ $available->price per token.";
                            $this->storeMessages($buy_order_id, $buyer_id, $msg=$buyer_msg);
                            $this->storeMessages($seller_order_id, $seller_id, $msg=$seller_msg);

                            if(isset($_SESSION['user_id'])) {
                                $logged_in_user = (int) $_SESSION['user_id'];
                                if ($this->check_user($logged_in_user) != false) {
                                    if ($logged_in_user == $buyer_id) {
                                        $message[] = $buyer_msg;
                                    } else if($logged_in_user == $seller_id) {
                                        $message[] = $seller_msg;
                                    }
                                }
                            }

                            // save changes
                            $this->demanded_qty = 0;
                            $available->Quantity = 0;

                        } elseif ($this->demanded_qty < $available->Quantity) {

                            $trade_qty = (float) $this->demanded_qty;

                            $cost_of_total_supply = $this->demanded_qty * $available->price; // traditional or cash
                            $cost_of_total_supply = (float) $cost_of_total_supply;

                            if ($buyer_balance_cash < $cost_of_total_supply) {
                                /*Record the message*/
                                $this->storeMessages($buy_order_id, $buyer_id, $msg="Transaction failed: You had insufficient cash balance.");
                                if ($_SESSION['user_id'] == $buyer_id) {
                                    $message[] = "Transaction failed: You have insufficient cash balance.";
                                }
                                $this->del_order($buy_order_id, $buyer_id);
                                break;
                            }
                            if (($seller_balance_btc == 0) || ($seller_balance_btc < $this->demanded_qty)) {
                                /*Record the message*/
                                $this->storeMessages($seller_order_id, $seller_id, $msg="Transaction failed: You had insufficient RMT balance.");
                                if ($_SESSION['user_id'] == $seller_id) {
                                    $message[] = "Transaction failed: You had insufficient RMT balance";
                                }
                                $this->del_order($seller_order_id, $seller_id);
                                break;
                            }

                            if ($buyer_id != $seller_id) {
                                $new_seller_balance_cash = $seller_balance_cash + $cost_of_total_supply;  // traditional or cash
                                $new_seller_balance_btc = $seller_balance_btc - $this->demanded_qty; // deduct the btc sold

                                $new_buyer_balance_btc = $buyer_balance_btc + $this->demanded_qty; // btc
                                $new_buyer_balance_cash = $buyer_balance_cash - $cost_of_total_supply; // traditional or cash

                                // subtract the debit access (customers balance of $ or BTC)
                                $this->update_user_balance($assetType = 'btc', $balance = $new_buyer_balance_btc, $user_id = $buyer_id);

                                // increment the credit asset (customers balance of $ or BTC)
                                $this->update_user_balance($assetType = 'traditional', $balance = $new_seller_balance_cash, $user_id = $seller_id);

                                // record the commission in the commission account
                                // decrease respective balances
                                $this->update_user_balance($assetType = 'btc', $balance = $new_seller_balance_btc, $user_id = $seller_id);

                                $this->update_user_balance($assetType = 'traditional', $balance = $new_buyer_balance_cash, $user_id = $buyer_id);

                            }

                            // update the quantity field for $availableQuantity
                            $availableQuantity = $available->Quantity - $this->demanded_qty;
                            $this->update_quantity($top_table = $this->top_sell_table, $availableQuantity, $available->orderId);

                            // Delete the row from Buy list
                            $this->delete_order($this->top_buy_table, $buy_order_id);

                            // Mark this order status 1 i.e transaction successful
                            $this->updateOrderStatus($buy_order_id, $status='1');

                            // Record messages
                            $buyer_msg = "Transaction successful: You bought $this->demanded_qty RMT for $ $cost_of_total_supply at the rate of $ $available->price per token.";
                            $seller_msg = "Transaction successful: You sold $this->demanded_qty RMT for $ $cost_of_total_supply at the rate of $ $available->price per token.";
                            $this->storeMessages($buy_order_id, $buyer_id, $msg=$buyer_msg);
                            $this->storeMessages($seller_order_id, $seller_id, $msg=$seller_msg);

                            if(isset($_SESSION['user_id'])) {
                                $logged_in_user = (int) $_SESSION['user_id'];
                                if ($this->check_user($logged_in_user) != false) {
                                    if ($logged_in_user == $buyer_id) {
                                        $message[] = $buyer_msg;
                                    } else if($logged_in_user == $seller_id) {
                                        $message[] = $seller_msg;
                                    }
                                }
                            }

                            // save changes
                            $this->demanded_qty = 0;
                        }

                        // Record the transaction
                        $this->record_transaction($buyer_id,$buy_order_id, $buy_amount, $buy_commission='0', $seller_id, $available->orderId, $available->price, $sell_commission = '0', $trade_qty);
                    } else {
                        return false;
                    }
                    $this->OrderMatchingQuery();
                }
                return $message;
            }
            return false;
        }
        return false;
    }

    private function record_transaction($buyer, $buy_order_id, $buy_amount, $buy_commission, $seller, $sell_order_id, $sell_amount, $sell_commission, $trade_qty) {
        if ($this->databaseConnection()) {
            $now = $this->time_now();
            $query = $this->db_connection->prepare("
            INSERT INTO $this->transaction_table(`TransactionId`, `a_buyer`, `A_OrderId`, `A_Amount`, `A_Commission`, `b_seller`, `B_OrderId`, `B_Amount`, `B_Commission`, `qty_traded`, `UpdateDate`, `InsertDate`, `SaveDate`)
            VALUES ('', :buyer,:buy_order_id, :buy_amount, :buy_commission, :seller, :sell_order_id, :sell_amount, :sell_commission, :trade_qty, NULL, '$now', '$now')
            ");
            $query->bindParam("buyer", $buyer);
            $query->bindParam("buy_order_id", $buy_order_id);
            $query->bindParam("buy_amount", $buy_amount);
            $query->bindParam("buy_commission", $buy_commission);
            $query->bindParam("seller", $seller);
            $query->bindParam("sell_order_id", $sell_order_id);
            $query->bindParam("sell_amount", $sell_amount);
            $query->bindParam("sell_commission", $sell_commission);
            $query->bindParam("trade_qty", $trade_qty);
            if($query->execute()) {
                return true;
            }
        }
        return false;
    }

    private function delete_order($top_table, $orderId) {
        if ($this->databaseConnection()) {
            $query = $this->db_connection->prepare("DELETE FROM `$top_table` WHERE `orderId`=:id LIMIT 1");
            $query->bindParam('id', $orderId);
            if($query->execute()) {
                return true;
            }
            return false;
        }
        return false;
    }

    private function update_quantity($top_table, $qty, $orderId) {

        if ($this->databaseConnection()) {
            $now = $this->time_now();
            $query = $this->db_connection->prepare("
                UPDATE $top_table
                SET `quantity`= :qty, `insertDate`='$now'
                WHERE orderId = :orderId
                LIMIT 1
            ");
            $query->bindParam('qty', $qty);
            $query->bindParam('orderId', $orderId);
            if($query->execute()) {
                return true;
            }
            return false;
        }
        return false;
    }

    private function insert_market_order($customerId, $orderTypeId, $OfferAssetTypeId=null, $WantAssetTypeId=null, $qty, $price) {
        if ($this->databaseConnection()) {
            $now = $this->time_now();
            $query = $this->db_connection->prepare("INSERT INTO $this->orders_table (`OrderId`, `CustomerId`, `OrderTypeId`, `OfferAssetTypeId`, `WantAssetTypeId`, `Quantity`, `Price`, `OrderStatusId`, `MarketOrder`, `UpdateDate`, `InsertDate`, `SaveDate`)
                                VALUES ('', :u, :a, :d, :e, :b, :c, 1, 1, NULL, '$now', NULL)
                              ");
            $query->bindParam(':u', $customerId, PDO::PARAM_INT);
            $query->bindParam(':a', $orderTypeId, PDO::PARAM_INT);
            $query->bindParam(':d', $OfferAssetTypeId, PDO::PARAM_STR);
            $query->bindParam(':e', $WantAssetTypeId, PDO::PARAM_STR);
            $query->bindParam(':b', $qty, PDO::PARAM_STR);
            $query->bindParam(':c', $price, PDO::PARAM_STR);

            if ($query->execute()) {
                $insertedrowid = $this->db_connection->lastInsertId();

                $trade_type = ($orderTypeId=='1') ? "sell" : "buy";
                $messages = "You entered an instant $trade_type order for $qty token at $ $price per token for $ ".$qty*$price;
                $this->storeMessages($insertedrowid, $customerId, $messages);

                return (int) $insertedrowid;
            }
            return false;
        }
        return false;
    }


    /**
     * Buy Sell Instantly
     * @param $order_type
     * @param $qty
     * @return array|bool
     */
    public function market_order($order_type, $qty) {

        if ($this->databaseConnection()) {

            $message = array();

            // Check if it is a buy or sell
            if ($order_type == 'buy') {

                if(is_float($qty) || is_int($qty)) {
                    if ($qty > 0) {

                        $sell_list = $this->get_top_buy_sell_list($top_table=TOP_SELL_TABLE, $asc_desc='ASC');

                        if (!empty($sell_list)) {

                            /*Code to find the last iteration of loop. Required to print shortage of token supply.*/
                            end($sell_list);
                            $last_iter = key($sell_list);

                            foreach ($sell_list as $key=>$available) {

                                $trade_qty = 0;
                                $sell_order_id = (int)$available->OrderId;
                                $available->Quantity = (float)$available->Quantity;

                                if ($available->Quantity <= 0 || $qty <= 0) {
                                    if (isset($message)) {
                                        if ($available->Quantity <= 0) {
                                            $message[] = "Oops! There's shortage of the availability of tokens.";
                                        } else if($qty <= 0) {
                                            //$message[] = "The demanded asset is nill.";
                                        }
                                    } else {
                                        exit;
                                    }
                                    return $message;
                                }

                                $seller_id = $available->customerId;
                                $seller_cash_balance = (float) $this->check_customer_balance($assetType = 'traditional', $seller_id)->Balance;
                                $seller_bit_balance = (float) $this->check_customer_balance($assetType = 'btc', $seller_id)->Balance;
                                $buyer_id = $_SESSION['user_id'];
                                $buyer_cash_balance = (float) $this->check_customer_balance($assetType = 'traditional', $buyer_id)->Balance;
                                $buyer_bit_balance = (float) $this->check_customer_balance($assetType = 'btc', $buyer_id)->Balance;

                                switch ($qty) {
                                    case ($qty > $available->Quantity):

                                        $trade_qty = (float)$available->Quantity;
                                        $cost_of_total_supply = $available->Quantity * $available->Price; // traditional or cash

                                        if ($buyer_cash_balance < $cost_of_total_supply) {
                                            $message[] = "Transaction failed: You have insufficient cash balance.";
                                            $this->storeMessages($buy_order_id=null, $buyer_id, $msg="Transaction failed: You had insufficient cash balance.");
                                            return $message;
                                        }
                                        if (($seller_bit_balance == 0) || ($seller_bit_balance < $available->Quantity)) {
                                            $message[] = "Transaction failed: The seller has insufficient token balance.";
                                            $this->storeMessages($sell_order_id, $seller_id, $msg="Transaction failed: The seller has insufficient token balance.");
                                            return $message;
                                        }

                                        $new_seller_cash_balance = $seller_cash_balance + $cost_of_total_supply;  // traditional or cash
                                        $new_seller_bit_balance = $seller_bit_balance - $available->Quantity;
                                        $new_buyer_bit_balance = $buyer_bit_balance + $available->Quantity; // traditional or cash
                                        $new_buyer_cash_balance = $buyer_cash_balance - $cost_of_total_supply; // traditional

                                        $insert_market_order = $this->insert_market_order($_SESSION['user_id'], $orderTypeId='0', $OfferAssetTypeId='USD', $WantAssetTypeId='RMT', $available->Quantity, $available->Price);

                                        $buy_order_id = 0;
                                        if($insert_market_order == false) {
                                            return false;
                                        } else if(is_int($insert_market_order)) {
                                            $buy_order_id = (int)$insert_market_order;
                                        } else {
                                            return false;
                                        }

                                        if ($buyer_id != $seller_id) {
                                            // increment the bits of buyer
                                            $this->update_user_balance($assetType = 'btc', $balance = $new_buyer_bit_balance, $user_id = $buyer_id);

                                            // deduct cash of buyer
                                            $this->update_user_balance($assetType = 'traditional', $balance = $new_buyer_cash_balance, $user_id = $buyer_id);

                                            // increase the cash of seller
                                            $this->update_user_balance($assetType = 'traditional', $balance = $new_seller_cash_balance, $user_id = $seller_id);

                                            // deduct bits of seller
                                            $this->update_user_balance($assetType = 'btc', $balance = $new_seller_bit_balance, $user_id = $seller_id);
                                        }

                                        // Record the transaction
                                        $this->record_transaction($buyer_id, $buy_order_id, $available->Price, $buy_commission='0', $seller_id, $available->OrderId, $available->Price, $sell_commission = '0', $trade_qty);

                                        // Delete the row from Sell list
                                        $this->delete_order($this->top_sell_table, $available->OrderId);

                                        // Update Order Status in Order table
                                        $this->UpdateOrderStatus($available->OrderId, '1');

                                        $message[] = "Instant Transaction Successful: You bought $available->Quantity RMT at $ $available->Price per token for $ $cost_of_total_supply.";

                                        // Record message in DB
                                        $this->storeMessages($buy_order_id, $buyer_id, $msg="Instant Transaction Successful: You bought $available->Quantity RMT at $ $available->Price per token for $ $cost_of_total_supply.");
                                        $this->storeMessages($sell_order_id, $seller_id, $msg="Transaction Successful: You sold $available->Quantity RMT at $ $available->Price per token for $ $cost_of_total_supply.");

                                        $qty = $qty - $available->Quantity;
                                        $available->Quantity = 0;

                                        // save changes
                                        break;
                                    case ($qty == $available->Quantity):

                                        $trade_qty = (float)$available->Quantity;
                                        $cost_of_total_supply = $available->Quantity * $available->Price; // traditional or cash

                                        if ($buyer_cash_balance < $cost_of_total_supply) {
                                            $message[] = "Instant Transaction failed: You have insufficient cash balance.";
                                            $this->storeMessages($buy_order_id=null, $buyer_id, $msg="Transaction failed: You had insufficient cash balance.");
                                            return $message;
                                        }
                                        if (($seller_bit_balance == 0) || ($seller_bit_balance < $available->Quantity)) {
                                            $message[] = "Instant Transaction failed: The seller has insufficient token balance.";
                                            $this->storeMessages($sell_order_id, $seller_id, $msg="Transaction failed: The seller has insufficient token balance.");
                                            return $message;
                                        }

                                        $new_seller_cash_balance = $seller_cash_balance + $cost_of_total_supply;  // traditional or cash
                                        $new_seller_bit_balance = $seller_bit_balance - $qty;
                                        $new_buyer_cash_balance = $buyer_cash_balance - $cost_of_total_supply; // traditional
                                        $new_buyer_bit_balance = $buyer_bit_balance + $available->Quantity; // traditional or cash

                                        $insert_market_order = $this->insert_market_order($_SESSION['user_id'], $orderTypeId='0', $OfferAssetTypeId='USD', $WantAssetTypeId='RMT', $available->Quantity, $available->Price);

                                        $buy_order_id = 0;
                                        if($insert_market_order == false) {
                                            return false;
                                        } else if(is_int($insert_market_order)) {
                                            $buy_order_id = (int)$insert_market_order;
                                        } else {
                                            return false;
                                        }

                                        if ($buyer_id != $seller_id) {
                                            // increment the bits of buyer
                                            $this->update_user_balance($assetType = 'btc', $balance = $new_buyer_bit_balance, $user_id = $buyer_id);

                                            // deduct cash of buyer
                                            $this->update_user_balance($assetType = 'traditional', $balance = $new_buyer_cash_balance, $user_id = $buyer_id);

                                            // increase the cash of seller
                                            $this->update_user_balance($assetType = 'traditional', $balance = $new_seller_cash_balance, $user_id = $seller_id);

                                            // deduct bits of seller
                                            $this->update_user_balance($assetType = 'btc', $balance = $new_seller_bit_balance, $user_id = $seller_id);
                                        }

                                        $message[] = "Instant Transaction Successful: You bought $qty tokens at $ $available->Price per token for $ $cost_of_total_supply.";

                                        // Record message in DB
                                        $this->storeMessages($buy_order_id, $buyer_id, $msg="Instant Transaction Successful: You bought $available->Quantity RMT at $ $available->Price per token for $ $cost_of_total_supply.");
                                        $this->storeMessages($sell_order_id, $seller_id, $msg="Transaction Successful: You sold $available->Quantity RMT at $ $available->Price per token for $ $cost_of_total_supply.");

                                        $qty = $qty - $available->Quantity; // should be equal to 0
                                        $available->Quantity = 0;

                                        // Record the transaction
                                        $this->record_transaction($buyer_id, $buy_order_id, $available->Price, $buy_commission='0', $seller_id, $available->OrderId, $available->Price, $sell_commission = '0', $trade_qty);

                                        // Delete the row from Sell list
                                        $this->delete_order($this->top_sell_table, $available->OrderId);

                                        // Update Order Status in Order table
                                        $this->UpdateOrderStatus($buy_order_id, '1');
                                        $this->UpdateOrderStatus($available->OrderId, '1');

                                        break;
                                    case ($qty < $available->Quantity):

                                        $trade_qty = (float) $qty;
                                        $cost_of_total_supply = $qty * $available->Price; // traditional or cash

                                        if ($buyer_cash_balance < $cost_of_total_supply) {
                                            $message[] = "Instant Transaction failed: You have insufficient cash balance.";
                                            $this->storeMessages($buy_order_id=null, $buyer_id, $msg="Transaction failed: You had insufficient cash balance.");
                                            return $message;
                                        }
                                        if (($seller_bit_balance == 0) || ($seller_bit_balance < $qty)) {
                                            $message[] = "Instant Transaction failed: The seller has insufficient token balance.";
                                            $this->storeMessages($sell_order_id, $seller_id, $msg="Transaction failed: The seller has insufficient token balance.");
                                            return $message;
                                        }

                                        $new_seller_cash_balance = $seller_cash_balance + $cost_of_total_supply;  // traditional or cash
                                        $new_seller_bit_balance = $seller_bit_balance - $qty;
                                        $new_buyer_cash_balance = $buyer_cash_balance - $cost_of_total_supply; // traditional
                                        $new_buyer_bit_balance = $buyer_bit_balance + $qty; // traditional or cash

                                        $insert_market_order = $this->insert_market_order($_SESSION['user_id'], $orderTypeId='0', $OfferAssetTypeId='USD', $WantAssetTypeId='RMT', $qty, $available->Price);

                                        $buy_order_id = 0;
                                        if($insert_market_order == false) {
                                            return false;
                                        } else if(is_int($insert_market_order)) {
                                            $buy_order_id = (int)$insert_market_order;
                                        } else {
                                            return false;
                                        }

                                        if ($buyer_id != $seller_id) {
                                            // increment the bits of buyer
                                            $this->update_user_balance($assetType = 'btc', $balance = $new_buyer_bit_balance, $user_id = $buyer_id);

                                            // deduct cash of buyer
                                            $this->update_user_balance($assetType = 'traditional', $balance = $new_buyer_cash_balance, $user_id = $buyer_id);

                                            // increase the cash of seller
                                            $this->update_user_balance($assetType = 'traditional', $balance = $new_seller_cash_balance, $user_id = $seller_id);

                                            // deduct bits of seller
                                            $this->update_user_balance($assetType = 'btc', $balance = $new_seller_bit_balance, $user_id = $seller_id);
                                        }

                                        // Record the transaction
                                        $this->record_transaction($buyer_id, $buy_order_id, $available->Price, $buy_commission='0', $seller_id, $available->OrderId, $available->Price, $sell_commission = '0', $trade_qty);

                                        $available->Quantity = $available->Quantity - $qty;

                                        // update the quantity field for supply
                                        $this->update_quantity($top_table = $this->top_sell_table, $available->Quantity, $available->OrderId);

                                        // Update Order Status in Order table
                                        $this->UpdateOrderStatus($buy_order_id, '1');

                                        $message[] = "Instant Transaction Successful: You bought $qty tokens at $ $available->Price per token for $ $cost_of_total_supply.";

                                        // Record message in DB
                                        $this->storeMessages($buy_order_id, $buyer_id, $msg="Instant Transaction Successful: You bought $qty RMT at $ $available->Price per token for $ $cost_of_total_supply.");
                                        $this->storeMessages($sell_order_id, $seller_id, $msg="Transaction Successful: You sold $qty RMT at $ $available->Price per token for $ $cost_of_total_supply.");

                                        // update the quantity field for demand
                                        $qty = 0;

                                        break;
                                }
                                if (($available->Quantity <= 0) && ($qty > 0) && ($key === $last_iter)) {
                                    /*The supply of token is 0. Stop further transaction. */
                                    $message[] = "Instant Transaction failure: There's no token left to be sold any more. $qty tokens could not be bought.";
                                    $this->storeMessages($buy_order_id=null, $buyer_id, $msg="There's no token left to be sold any more. $qty tokens could not be bought.");
                                }
                            }
                            return $message;
                        } else {
                            $message[] = "empty_sell_list";
                            return $message;
                        }
                    }
                }

            } elseif ($order_type == 'sell') {
                if(is_float($qty) || is_int($qty)) {
                    if ($qty > 0) {

                        $buy_list = $this->get_top_buy_sell_list($top_table=TOP_BUYS_TABLE, $asc_desc='DESC');

                        if (!empty($buy_list)) {
                            foreach ($buy_list as $available) {

                                $trade_qty = 0;
                                $buy_order_id = (int) $available->OrderId;
                                $available->Quantity = (float)$available->Quantity;

                                if ($available->Quantity <= 0 || $qty <= 0) {
                                    if (isset($message)) {
                                        if ($available->Quantity <= 0) {
                                            $message[] = "Instant Transaction Failure: The available asset is nill.";
                                        } else if($qty <= 0) {
                                            //$message[] = "The demanded asset is nill.";
                                        }
                                        return $message;
                                    } else {
                                        exit;
                                    }
                                }

                                $seller_id = $_SESSION['user_id'];
                                $seller_cash_balance = $this->check_customer_balance($assetType = 'traditional', $seller_id)->Balance;
                                $seller_bit_balance = $this->check_customer_balance($assetType = 'btc', $seller_id)->Balance;
                                $buyer_id = $available->customerId;
                                $buyer_cash_balance = $this->check_customer_balance($assetType = 'traditional', $buyer_id)->Balance;
                                $buyer_bit_balance = $this->check_customer_balance($assetType = 'btc', $buyer_id)->Balance;

                                switch ($qty) {
                                    case ($qty > $available->Quantity):

                                        $trade_qty = (float) $available->Quantity;
                                        $cost_of_total_supply = $available->Quantity * $available->Price; // traditional or cash

                                        if ($buyer_cash_balance < $cost_of_total_supply) {
                                            $message[] = "Instant Transaction failed: The buyer has insufficient cash balance.";
                                            $this->storeMessages($buy_order_id, $buyer_id, $msg="Your order no $buy_order_id was unprocessed due to insufficient balance.");
                                            $this->storeMessages($sell_order_id=null, $seller_id, $msg="A transaction was unprocessed due to insufficient balance of buyer with id $buyer_id.");
                                            return $message;
                                        }
                                        if (($seller_bit_balance == 0) || ($seller_bit_balance < $available->Quantity)) {
                                            $message[] = "Instant Transaction failed: You have insufficient token balance.";
                                            $this->storeMessages($buy_order_id, $buyer_id, $msg="Your order no $buy_order_id was unprocessed due to insufficient token balance of seller with id $seller_id.");
                                            $this->storeMessages($sell_order_id=null, $seller_id, $msg="Transaction failed: You have insufficient token balance.");
                                            return $message;
                                        }

                                        $new_seller_cash_balance = $seller_cash_balance + $cost_of_total_supply;  // traditional or cash
                                        $new_seller_bit_balance = $seller_bit_balance - $available->Quantity; // deduct the btc sold
                                        $new_buyer_cash_balance = $buyer_cash_balance - $cost_of_total_supply; // traditional
                                        $new_buyer_bit_balance = $buyer_bit_balance + $available->Quantity; // btc

                                        $insert_market_order = $this->insert_market_order($_SESSION['user_id'], $orderTypeId='1', $OfferAssetTypeId='RMT', $WantAssetTypeId='USD', $available->Quantity, $available->Price);

                                        $sell_order_id = 0;
                                        if($insert_market_order == false) {
                                            return false;
                                        } else if(is_int($insert_market_order)) {
                                            $sell_order_id = (int)$insert_market_order;
                                        } else {
                                            return false;
                                        }

                                        if ($buyer_id != $seller_id) {
                                            // increment the bits of buyer
                                            $this->update_user_balance($assetType = 'btc', $balance = $new_buyer_bit_balance, $user_id = $buyer_id);

                                            // deduct cash of buyer
                                            $this->update_user_balance($assetType = 'traditional', $balance = $new_buyer_cash_balance, $user_id = $buyer_id);

                                            // increase the cash of seller
                                            $this->update_user_balance($assetType = 'traditional', $balance = $new_seller_cash_balance, $user_id = $seller_id);

                                            // deduct bits of seller
                                            $this->update_user_balance($assetType = 'btc', $balance = $new_seller_bit_balance, $user_id = $seller_id);
                                        }

                                        // Delete the row from buy list
                                        $this->delete_order($this->top_buy_table, $available->OrderId);

                                        // Update Order Status in Order table
                                        $this->UpdateOrderStatus($available->OrderId, '1');

                                        // Record the transaction
                                        $this->record_transaction($buyer_id, $available->OrderId, $available->Price, $buy_commission='0', $seller_id, $sell_order_id, $available->Price, $sell_commission = '0', $trade_qty);

                                        $message[] = "Instant Transaction Successful: You sold $available->Quantity tokens for $ $cost_of_total_supply.";
                                        $this->storeMessages($sell_order_id, $seller_id, $msg="Instant Transaction Successful: You sold $available->Quantity RMT for $ $cost_of_total_supply.");
                                        $this->storeMessages($buy_order_id, $buyer_id, $msg="Transaction Successful: You bought $available->Quantity RMT for $ $cost_of_total_supply.");

                                        $qty = $qty - $available->Quantity;

                                        break;
                                    case ($qty == $available->Quantity):

                                        $trade_qty = (float) $available->Quantity;
                                        $cost_of_total_supply = $available->Quantity * $available->Price; // traditional or cash

                                        if ($buyer_cash_balance < $cost_of_total_supply) {
                                            $message[] = "Instant Transaction failed: The buyer has insufficient cash balance.";
                                            $this->storeMessages($buy_order_id, $buyer_id, $msg="Your order no $buy_order_id was unprocessed due to insufficient balance.");
                                            $this->storeMessages($sell_order_id=null, $seller_id, $msg="A transaction was unprocessed due to insufficient balance of buyer with id $buyer_id.");
                                            return $message;
                                        }
                                        if (($seller_bit_balance == 0) || ($seller_bit_balance < $available->Quantity)) {
                                            $message[] = "Instant Transaction failed: You have insufficient token balance.";
                                            $this->storeMessages($buy_order_id, $buyer_id, $msg="Your order no $buy_order_id was unprocessed due to insufficient token balance of seller with id $seller_id.");
                                            $this->storeMessages($sell_order_id=null, $seller_id, $msg="Transaction failed: You have insufficient token balance.");
                                            return $message;
                                        }

                                        $new_seller_cash_balance = $seller_cash_balance + $cost_of_total_supply;  // traditional or cash
                                        $new_seller_bit_balance = $seller_bit_balance - $available->Quantity; // deduct the btc sold
                                        $new_buyer_cash_balance = $buyer_cash_balance - $cost_of_total_supply; // traditional
                                        $new_buyer_bit_balance = $buyer_bit_balance + $available->Quantity; // traditional or cash

                                        $insert_market_order = $this->insert_market_order($_SESSION['user_id'], $orderTypeId='1', $OfferAssetTypeId='RMT', $WantAssetTypeId='USD', $available->Quantity, $available->Price);

                                        $sell_order_id = 0;
                                        if($insert_market_order == false) {
                                            return false;
                                        } else if(is_int($insert_market_order)) {
                                            $sell_order_id = (int)$insert_market_order;
                                        } else {
                                            return false;
                                        }

                                        if ($buyer_id != $seller_id) {
                                            // increment the bits of buyer
                                            $this->update_user_balance($assetType = 'btc', $balance = $new_buyer_bit_balance, $user_id = $buyer_id);

                                            // deduct cash of buyer
                                            $this->update_user_balance($assetType = 'traditional', $balance = $new_buyer_cash_balance, $user_id = $buyer_id);

                                            // subtract the cash of seller
                                            $this->update_user_balance($assetType = 'traditional', $balance = $new_seller_cash_balance, $user_id = $seller_id);

                                            // deduct bits of seller
                                            $this->update_user_balance($assetType = 'btc', $balance = $new_seller_bit_balance, $user_id = $seller_id);
                                        }

                                        $message[] = "Instant Transaction Successful: You sold $qty tokens for $ $cost_of_total_supply.";
                                        $this->storeMessages($sell_order_id, $seller_id, $msg="Instant Transaction Successful: You sold $available->Quantity RMT for $ $cost_of_total_supply.");
                                        $this->storeMessages($buy_order_id, $buyer_id, $msg="Transaction Successful: You bought $available->Quantity RMT for $ $cost_of_total_supply.");

                                        $qty = $qty - $available->Quantity;

                                        // Update Order Status in Order table
                                        $this->UpdateOrderStatus($sell_order_id, '1');
                                        $this->UpdateOrderStatus($available->OrderId, '1');

                                        // Record the transaction
                                        $this->record_transaction($buyer_id, $available->OrderId, $available->Price, $buy_commission='0', $seller_id, $sell_order_id, $available->Price, $sell_commission = '0', $trade_qty);

                                        // Delete the row from buy list
                                        $this->delete_order($this->top_buy_table, $available->OrderId);

                                        break;
                                    case ($qty < $available->Quantity):

                                        $trade_qty = (float) $qty;
                                        $available->Quantity = $available->Quantity - $qty;
                                        $cost_of_total_supply = $qty * $available->Price; // traditional or cash

                                        if ($buyer_cash_balance < $cost_of_total_supply) {
                                            $message[] = "Instant Transaction failed: The buyer has insufficient cash balance.";
                                            $this->storeMessages($buy_order_id, $buyer_id, $msg="Your order no $buy_order_id was unprocessed due to insufficient balance.");
                                            $this->storeMessages($sell_order_id=null, $seller_id, $msg="A transaction was unprocessed due to insufficient balance of buyer with id $buyer_id.");
                                            return $message;
                                        }
                                        if (($seller_bit_balance == 0) || ($seller_bit_balance < $qty)) {
                                            $message[] = "Instant Transaction failed: You have insufficient token balance.";
                                            $this->storeMessages($buy_order_id, $buyer_id, $msg="Your order no $buy_order_id was unprocessed due to insufficient token balance of seller with id $seller_id.");
                                            $this->storeMessages($sell_order_id=null, $seller_id, $msg="Transaction failed: You have insufficient token balance.");
                                            return $message;
                                        }

                                        $new_seller_cash_balance = $seller_cash_balance + $cost_of_total_supply;  // traditional or cash
                                        $new_seller_bit_balance = $seller_bit_balance - $qty; // deduct the btc sold
                                        $new_buyer_cash_balance = $buyer_cash_balance - $cost_of_total_supply; // traditional
                                        $new_buyer_bit_balance = $buyer_bit_balance + $qty; // traditional or cash

                                        $insert_market_order = $this->insert_market_order($_SESSION['user_id'], $orderTypeId='1', $OfferAssetTypeId='RMT', $WantAssetTypeId='USD', $qty, $available->Price);

                                        $sell_order_id = 0;
                                        if($insert_market_order == false) {
                                            return false;
                                        } else if(is_int($insert_market_order)) {
                                            $sell_order_id = (int)$insert_market_order;
                                        } else {
                                            return false;
                                        }

                                        if ($buyer_id != $seller_id) {
                                            // increment the bits of buyer
                                            $this->update_user_balance($assetType = 'btc', $balance = $new_buyer_bit_balance, $user_id = $buyer_id);

                                            // deduct cash of buyer
                                            $this->update_user_balance($assetType = 'traditional', $balance = $new_buyer_cash_balance, $user_id = $buyer_id);

                                            // subtract the cash of seller
                                            $this->update_user_balance($assetType = 'traditional', $balance = $new_seller_cash_balance, $user_id = $seller_id);

                                            // deduct bits of seller
                                            $this->update_user_balance($assetType = 'btc', $balance = $new_seller_bit_balance, $user_id = $seller_id);
                                        }

                                        // Record the transaction
                                        $this->record_transaction($buyer_id, $available->OrderId, $available->Price, $buy_commission='0', $seller_id, $sell_order_id, $available->Price, $sell_commission = '0', $trade_qty);

                                        // update the quantity field for supply
                                        $this->update_quantity($top_table = $this->top_buy_table, $available->Quantity, $available->OrderId);

                                        // Update Order Status in Order table
                                        $this->UpdateOrderStatus($sell_order_id, '1');

                                        $message[] = "Instant Transaction Successful: You sold $qty tokens for $ $cost_of_total_supply.";
                                        $this->storeMessages($sell_order_id, $seller_id, $msg="Instant Transaction Successful: You sold $qty RMT for $ $cost_of_total_supply.");
                                        $this->storeMessages($buy_order_id, $buyer_id, $msg="Transaction Successful: You bought $qty RMT for $ $cost_of_total_supply.");

                                        // update the quantity field for demand
                                        $qty = 0;

                                        break;
                                }
                            }
                            return $message;
                        } else {
                            $message[] = "empty_buy_list";
                            return $message;
                        }
                    }
                }
            }
        }
        return false;
    }

    public function last_transaction_list($start=0, $limit = 10) {
        if ($this->databaseConnection()) {

            $list = array();

            $query = $this->db_connection->query("
                SELECT TransactionId AS T_ID, a_buyer AS BUYER_ID, b_seller AS SELLER_ID, (SELECT ".USERS_TABLE.".Name FROM ".USERS_TABLE." WHERE ".USERS_TABLE.".CustomerId=BUYER_ID) AS BUYER, (SELECT ".USERS_TABLE.".Name FROM ".USERS_TABLE." WHERE ".USERS_TABLE.".CustomerId=SELLER_ID) AS SELLER, B_AMOUNT AS TRADE_PRICE, ".TRANSACTIONS_TABLE.".InsertDate, ".TRANSACTIONS_TABLE.".qty_traded AS TRADED_QTY
                FROM ".TRANSACTIONS_TABLE.", ".USERS_TABLE."
                GROUP BY T_ID
                ORDER BY T_ID DESC
                LIMIT $start, $limit
            ");

            if ($query->rowCount() > 0) {
                while ($ls = $query->fetchObject()) {
                    $list[] = $ls;
                }
                return $list;
            }
            return false;
        }
        return false;
    }

    public function UserBalanceList($is_active=null) {
        if ($this->databaseConnection()) {

            $list = array();

            $extraQuerry = "";

            if ($is_active != null) {
                $extraQuerry = "WHERE ".USERS_TABLE.".is_active = 0 OR ".USERS_TABLE.".is_active = 1";
            } else {
                $extraQuerry = "WHERE ".USERS_TABLE.".is_active = 1";
            }

            $query = $this->db_connection->query("
                SELECT ".USERS_TABLE.".CustomerId AS UID, ".USERS_TABLE.".Name, ".USERS_TABLE.".is_active, ".USERS_TABLE.".fb_id AS FACEBOOK_ID,
                (SELECT ".CREDITS_TABLE.".Balance FROM ".CREDITS_TABLE." WHERE ".CREDITS_TABLE.".AssetTypeId='btc' AND ".CREDITS_TABLE.".CustomerId=UID) AS BTC, 
                (SELECT ".CREDITS_TABLE.".Balance FROM ".CREDITS_TABLE." WHERE ".CREDITS_TABLE.".AssetTypeId='traditional' AND ".CREDITS_TABLE.".CustomerId=UID) AS CASH 
                FROM ".USERS_TABLE.", ".CREDITS_TABLE." 
                $extraQuerry
                GROUP BY UID ORDER BY MAX(BTC) DESC
            ");

            if ($query->rowCount() > 0) {
                while ($ls = $query->fetchObject()) {
                    $list[] = $ls;
                }
                return $list;
            }
            return false;
        }
        return false;
    }

    public function LastTradedPrice() {
        if ($this->databaseConnection()) {

            $query = $this->db_connection->query("
                SELECT `B_Amount`,InsertDate FROM ".TRANSACTIONS_TABLE." ORDER BY `InsertDate` DESC LIMIT 1
            ");

            if ($query->rowCount() == 1) {
                $ls = $query->fetchObject();
                return $ls;
            }
            return false;
        }
        return false;
    }

    public function UserOrdersList($user_id, $start=0, $limit=10) {
        if ($this->databaseConnection()) {

            $list = array();
            $query = $this->db_connection->prepare("
            SELECT `OrderId`, `CustomerId`, `OrderTypeId`, `OfferAssetTypeId`, `WantAssetTypeId`, `Quantity`, `Price`, `OrderStatusId`, `MarketOrder`, `InsertDate`
            FROM ".ORDERS_TABLE."
            WHERE `CustomerId`=:u_id
            ORDER  BY InsertDate DESC
            LIMIT $start, $limit
            ");
            $query->bindParam('u_id', $user_id);
            if ($query->execute()) {
                if ($query->rowCount() > 0) {
                    while ($ls = $query->fetchObject()) {
                        $list[] = $ls;
                    }
                    return $list;
                }
            }
            return false;
        }
        return false;
    }

    protected function cancel_order($order_id=null, $user_id=null) {
        if ($this->databaseConnection()) {
            $query = $this->db_connection->prepare("
                DELETE FROM ".TOP_BUYS_TABLE." WHERE `orderId`=:id AND customerId = :cus_id;
                DELETE FROM ".TOP_SELL_TABLE." WHERE `orderId`=:id AND customerId = :cus_id
            ");

            $query->bindParam('id', $order_id);
            $query->bindParam('cus_id', $user_id);

            $query->execute();
            unset($query); // Unset the query

            $q = $this->db_connection->prepare("
                    UPDATE ".ORDERS_TABLE." SET `OrderStatusId`= 0 
                    WHERE `OrderId` = :ord
                    AND CustomerId = :cust_id
                  ");
            $q->bindParam('ord', $order_id);
            $q->bindParam('cust_id', $user_id);

            $q->execute();
            unset($q);

            $query2 = $this->db_connection->prepare("
                        SELECT * FROM ".TOP_BUYS_TABLE." WHERE `orderId`=:o_id;
                        SELECT * FROM ".TOP_SELL_TABLE." WHERE `orderId`=:o_id
                    ");
            $query2->bindParam('o_id', $order_id);

            if ($query2->execute()) {
                if ($query2->rowCount() == 0) {
                    if ($_SESSION['user_id']==ADMIN_ID) {
                        $this->storeMessages($order_id, ADMIN_ID, $msg="Order number $order_id was deleted by user id ".ADMIN_ID);
                        $this->storeMessages($order_id, $user_id, $msg="Order number $order_id was deleted by Admin.");
                    } else {
                        $this->storeMessages($order_id, $user_id, $msg="Order number $order_id was deleted by you.");
                    }
                    return true; // This means row was actually deleted
                }
            }
        }
        return false;
    }

    public function del_order($order_id, $usid=null) {
        if ($this->databaseConnection()) {

            $user_id = 0;
            if (!isset($_SESSION['user_id'])) {
                return false;
            }
            $user_id = (int) $_SESSION['user_id'];
            // Allow Admin to delete order, if its not admin check owner of order
            if ($usid == null) {
                $is_owner = $this->isUserOrderOwner($order_id, $user_id);

                if (!$is_owner) {
                    return false;
                }
            } else if(($usid != null) && ($user_id == ADMIN_ID)) {   // This else part to be used by admin in delete_orders_of_user()
                $user_id = $usid;
            } else {
                return false;
            }

            // Finally cancel the order
            return $this->cancel_order($order_id, $user_id);
        }
        return false;
    }

    public function storeMessages($order_id=null, $user_id=null, $msg=null) {
        if($this->databaseConnection()) {
            $now = $this->time_now();
            if ($user_id == false) {
                return false;
            }
            $username = $this->get_username($user_id);

            $query = $this->db_connection->prepare("
                INSERT INTO ".MSG_TABLE."(`id`, `order_id`, `username_key`, `username`, `messages`, `datetime`) 
                VALUES ('', :order_id, :user_id, :username, :msg, '$now')
            ");
            $query->bindParam("order_id", $order_id);
            $query->bindParam("user_id", $user_id);
            $query->bindParam("username", $username);
            $query->bindParam("msg", $msg);

            if ($query->execute()) {
                return true;
            }
        }
        return false;
    }

    /********************************
     *********Load More**********
     **********************************/

    /*Messages Load More*/
    function total_my_messages() {
        if ($this->databaseConnection()) {
            $my_total_messages = 0;
            if (isset($_SESSION['user_id'])) {
                $user_id = (int) $_SESSION['user_id'];
            } else {
                return $my_total_messages;
            }
            $query = $this->db_connection->prepare("
                SELECT COUNT(*) AS MY_TOTAL_MESSAGES
                FROM ".MSG_TABLE."
                WHERE `username_key`=:u_id
            ");
            $query->bindParam('u_id', $user_id);
            if ($query->execute()) {
                $fetch = $query->fetchObject();
                $my_total_messages = (int) $fetch->MY_TOTAL_MESSAGES;
            }
            return $my_total_messages;
        }
        return false;
    }

    public function total_my_orders()
    {
        if ($this->databaseConnection()) {
            $my_total_orders = 0;
            if (isset($_SESSION['user_id'])) {
                $user_id = (int)$_SESSION['user_id'];
            } else {
                return $my_total_orders;
            }
            $query = $this->db_connection->prepare("
                SELECT COUNT(*) AS MY_TOTAL_ORDERS
                FROM ".ORDERS_TABLE."
                WHERE `CustomerId`=:u_id
            ");
            $query->bindParam('u_id', $user_id);
            if ($query->execute()) {
                $fetch = $query->fetchObject();
                $my_total_orders = (int)$fetch->MY_TOTAL_ORDERS;
            }
            return $my_total_orders;
        }
        return false;
    }

    public function total_my_transactions()
    {
        if ($this->databaseConnection()) {
            $my_total_orders = 0;
            if (isset($_SESSION['user_id'])) {
                $user_id = (int)$_SESSION['user_id'];
            } else {
                return $my_total_orders;
            }
            $query = $this->db_connection->prepare("
                SELECT COUNT(*) AS MY_TOTAL_ORDERS
                FROM ".TRANSACTIONS_TABLE."
                WHERE `a_buyer`= :u_id OR `b_seller`= :u_id
            ");
            $query->bindParam('u_id', $user_id);
            if ($query->execute()) {
                $fetch = $query->fetchObject();
                $my_total_orders = (int)$fetch->MY_TOTAL_ORDERS;
            }
            return $my_total_orders;
        }
        return false;
    }

    public function total_recent_transactions()
    {
        if ($this->databaseConnection()) {
            $total_orders = 0;

            $query = $this->db_connection->prepare("
                SELECT COUNT(*) AS TOTAL_ORDERS
                FROM ".TRANSACTIONS_TABLE."
            ");
            if ($query->execute()) {
                $fetch = $query->fetchObject();
                $total_orders = (int)$fetch->TOTAL_ORDERS;
            }
            return $total_orders;
        }
        return false;
    }

    private function isUserOrderOwner($order_id=0, $user_id=0) {
        if ($this->databaseConnection()) {
            $query = $this->db_connection->prepare("
                SELECT `OrderId` FROM ".ORDERS_TABLE." 
                WHERE `OrderId`=:o_id 
                AND `CustomerId`=:c_id
                LIMIT 1
            ");
            $query->bindParam('o_id', $order_id);
            $query->bindParam('c_id', $user_id);
            if ($query->execute()) {
                if ($query->rowCount()==1) {
                    return true;
                }
            }
        }
        return false;
    }

    public function storeMessagesPublic($order_id=null, $user_id=null, $msg=null) {
        if ($this->databaseConnection()) {
            $this->storeMessages($order_id, $user_id, $msg);
        }
    }

    /*Add bank account*/
    public function add_bank_account($user_id, $holder, $bank_name, $account_num, $branch_name, $bank_addr, $bk_ctry) {
        if ($this->databaseConnection()) {
            $now = $this->time_now();
            $query = $this->db_connection->prepare(
                "INSERT INTO $this->bank_acc(`id`, `user_id`, `acc_holder`, `bank_name`, `acc_num`, `branch_name`, `bank_addr`, `bank_ctry`, `date_added`) 
                 VALUES ('', :uid, :holder, :bk_name, :acc_num, :br_name, :addr, :ctry, '$now')"
            );
            $query->bindParam("uid", $user_id);
            $query->bindParam("holder", $holder);
            $query->bindParam("bk_name", $bank_name);
            $query->bindParam("acc_num", $account_num);
            $query->bindParam("br_name", $branch_name);
            $query->bindParam("addr", $bank_addr);
            $query->bindParam("ctry", $bk_ctry);

            if ($query->execute()) {
                $this->storeMessages(null, $user_id, $msg="You added a new bank account number $account_num.");
                return true;
            }
        }
        return false;
    }

    public function send_notice_mail($reciever_email, $email_from, $email_sender, $email_subject, $email_body) {
        $mail = new SendMail();
        $do_mail = $mail->do_email($reciever_email, $email_from, $email_sender, $email_subject, $email_body);
        if ($do_mail==true) {
            return $do_mail;
        }
        return false;
    }

    public function get_bank_details($user_id, $acc=null) {
        $acc_details = null;
        if ($this->databaseConnection()) {

            $ex = "";
            if ($acc != null) {
                $ex = "AND `acc_num`=:acc";
            }

            $query = $this->db_connection->prepare(
                "SELECT * FROM $this->bank_acc WHERE `user_id`=:uid $ex"
            );
            $query->bindParam("uid", $user_id);
            if ($acc != null) {
                $query->bindParam("acc", $acc);
            }
            $query->execute();

            if ($query->rowCount() > 0) {
                while ($acc_info = $query->fetchObject()) {
                    $acc_details[] = $acc_info;
                }
            }
        }
        return $acc_details;
    }

    /*Fund transfer*/
    public function fund_transfer($fund_type, $from, $to, $amount, $remarks, $assetType) {
        $user_id = (isset($_SESSION['user_id']) && (int) $_SESSION['user_id'] != 0) ? $_SESSION['user_id'] : 0;
        $now = $this->time_now();
        if ($this->databaseConnection() && $user_id != 0) {

            $user_bal_currently = (float)$this->check_customer_balance($assetType, $user_id)->Balance;

            $new_bal = (float)$user_bal_currently - $amount;

            $ft = $this->update_user_balance($assetType, $new_bal, $user_id);

            if ($user_bal_currently == null || $ft == null) {
                return false;
            }

            $sign = ($assetType == 'btc') ? 'RTM':'$';

            $query = $this->db_connection->prepare(
                "INSERT INTO ".TRANSFER_INFO_TABLE."(`id`, `user_id`, `fund_type`, `tr_from`, `tr_to`, `fund_amount`, `remarks`, `datetime`) 
                 VALUES('', :uid, :fund_type, :tr_from, :tr_to, :tr_amount, :remarks, '$now')"
            );
            $query->bindParam('uid', $user_id);
            $query->bindParam('fund_type', $fund_type);
            $query->bindParam('tr_from', $from);
            $query->bindParam('tr_to', $to);
            $query->bindParam('tr_amount', $amount);
            $query->bindParam('remarks', $remarks);

            if ($query->execute()) {
                $this->storeMessages(null, $user_id, $msg="You have requested to transfer $sign $amount to bank account number $to.");
                return true;
            }
        }
        return false;
    }
    
    public function record_root_bal_update($uid, $bal_prev, $bal_now, $bal_type) {
        if ($this->databaseConnection()) {
            $now = $this->time_now();
            $root = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : 0;
            $query = $this->db_connection->prepare("
                INSERT INTO ".ADMIN_BAL_RECORDS."(`BalStatusHistoryId`, `user_id`, `bal_prev`, `bal_now`, `type`, `root_id`, `UpdateDate`) 
                VALUES ('', :uid, :prev, :now, :btype, :root, '$now')
            ");
            $query->bindParam("uid", $uid);
            $query->bindParam("prev", $bal_prev);
            $query->bindParam("now", $bal_now);
            $query->bindParam("btype", $bal_type);
            $query->bindParam("root", $root);

            if ($query->execute()) {
                return true;
            }
        }
        return false;
    }

    public function list_root_bal_changes() {
        if ($this->databaseConnection()) {
            $list_details = array();
            $query = $this->db_connection->prepare(" 
                SELECT ".ADMIN_BAL_RECORDS.".*, ".USERS_TABLE.".Name, ".USERS_TABLE.".Email 
                FROM ".ADMIN_BAL_RECORDS.", ".USERS_TABLE." 
                WHERE ".ADMIN_BAL_RECORDS.".user_id=".USERS_TABLE.".CustomerId
                ORDER BY UpdateDate DESC
                LIMIT 200
            ");
            $query->execute();

            if ($query->rowCount() > 0) {
                while ($list = $query->fetchObject()) {
                    $list_details[] = $list;
                }
            }
            return $list_details;
        }
        return false;
    }

    public function get_last_order_date($date=null) {
        if ($this->databaseConnection()) {
            $query = $this->db_connection->query("SELECT * FROM `orderbook` WHERE `InsertDate`> '$date'");
            if ($query->rowCount()) {
                return true;
            }
        }
        return false;
    }

    public function delete_orders_of_user($user_id=null) {
        if ($this->databaseConnection()) {
            $order_ids = array();
            $query = $this->db_connection->prepare("
            SELECT orderId FROM ".TOP_BUYS_TABLE." WHERE `customerId`=:uid
            UNION
            SELECT orderId FROM ".TOP_SELL_TABLE." WHERE `customerId`=:uid
            ");
            $query->bindParam('uid', $user_id);
            $query->execute();
            if ($query->rowCount() > 0) {
                while ($rr = $query->fetchObject()) {
                    $order_ids[] = $rr;
                }
                foreach ($order_ids as $oid) {
                    $this->del_order($oid->orderId, $user_id);
                }
                return true;
            }
        }
        return false;
    }

}

