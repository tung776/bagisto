<?php

namespace Webkul\Checkout;

use Carbon\Carbon;
use Webkul\Checkout\Repositories\CartRepository;
use Webkul\Checkout\Repositories\CartItemRepository;
use Webkul\Checkout\Repositories\CartAddressRepository;
use Webkul\Customer\Repositories\CustomerRepository;
use Webkul\Product\Repositories\ProductRepository;
use Webkul\Tax\Repositories\TaxCategoryRepository;
use Webkul\Checkout\Models\CartPayment;
use Webkul\Customer\Repositories\WishlistRepository;

/**
 * Facade for all the methods to be implemented in Cart.
 *
 * @author    Prashant Singh <prashant.singh852@webkul.com>
 * @author    Jitendra Singh <jitendra@webkul.com>
 * @copyright 2018 Webkul Software Pvt Ltd (http://www.webkul.com)
 */
class Cart {

    /**
     * CartRepository model
     *
     * @var mixed
     */
    protected $cart;

    /**
     * CartItemRepository model
     *
     * @var mixed
     */
    protected $cartItem;

    /**
     * CustomerRepository model
     *
     * @var mixed
     */
    protected $customer;

    /**
     * CartAddressRepository model
     *
     * @var mixed
     */
    protected $cartAddress;

    /**
     * ProductRepository model
     *
     * @var mixed
     */
    protected $product;

    /**
     * TaxCategoryRepository model
     *
     * @var mixed
     */
    protected $taxCategory;

    /**
     * WishlistRepository model
     *
     * @var mixed
     */
    protected $wishlist;

    /**
     * Suppress the session flash messages
     */
    protected $suppressFlash;

    /**
     * Create a new controller instance.
     *
     * @param  Webkul\Checkout\Repositories\CartRepository        $cart
     * @param  Webkul\Checkout\Repositories\CartItemRepository    $cartItem
     * @param  Webkul\Checkout\Repositories\CartAddressRepository $cartAddress
     * @param  Webkul\Customer\Repositories\CustomerRepository    $customer
     * @param  Webkul\Product\Repositories\ProductRepository      $product
     * @param  Webkul\Product\Repositories\TaxCategoryRepository  $taxCategory
     * @return void
     */
    public function __construct(
        CartRepository $cart,
        CartItemRepository $cartItem,
        CartAddressRepository $cartAddress,
        CustomerRepository $customer,
        ProductRepository $product,
        TaxCategoryRepository $taxCategory,
        WishlistRepository $wishlist
    )
    {
        $this->customer = $customer;

        $this->cart = $cart;

        $this->cartItem = $cartItem;

        $this->cartAddress = $cartAddress;

        $this->product = $product;

        $this->taxCategory = $taxCategory;

        $this->wishlist = $wishlist;

        $this->suppressFlash = false;
    }

    /**
     * Create new cart instance.
     *
     * @param integer $id
     * @param array   $data
     *
     * @return Boolean
     */
    public function create($id, $data, $qty = 1)
    {
        $cartData = [
            'channel_id' => core()->getCurrentChannel()->id,

            'global_currency_code' => core()->getBaseCurrencyCode(),

            'base_currency_code' => core()->getBaseCurrencyCode(),

            'channel_currency_code' => core()->getChannelBaseCurrencyCode(),

            'cart_currency_code' => core()->getCurrentCurrencyCode(),
            'items_count' => 1
        ];

        //Authentication details
        if(auth()->guard('customer')->check()) {
            $cartData['customer_id'] = auth()->guard('customer')->user()->id;
            $cartData['is_guest'] = 0;
            $cartData['customer_first_name'] = auth()->guard('customer')->user()->first_name;
            $cartData['customer_last_name'] = auth()->guard('customer')->user()->last_name;
            $cartData['customer_email'] = auth()->guard('customer')->user()->email;
        } else {
            $cartData['is_guest'] = 1;
        }

        $result = $this->cart->create($cartData);

        $this->putCart($result);

        if($result) {
            if($this->createItem($id, $data))
                return true;
            else
                return false;
        } else {
            session()->flash('error', trans('shop::app.checkout.cart.create-error'));
        }
    }

    /**
     * Add Items in a cart with some cart and item details.
     *
     * @param integer $id
     * @param array   $data
     *
     * @return void
     */
    public function add($id, $data) {
        $cart = $this->getCart();

        if($cart != null) {
            $ifExists = $this->checkIfItemExists($id, $data);

            if($ifExists) {
                $item = $this->cartItem->findOneByField('id', $ifExists);

                $data['quantity'] = $data['quantity'] + $item->quantity;

                $result = $this->updateItem($id, $data, $ifExists);
            } else {
                $result = $this->createItem($id, $data);
            }

            return $result;
        } else {
            return $this->create($id, $data);
        }
    }

    /**
     * To check if the items exists in the cart or not
     *
     * @return boolean
     */
    public function checkIfItemExists($id, $data) {
        $items = $this->getCart()->items;

        foreach($items as $item) {
            if($id == $item->product_id) {
                $product = $this->product->findOnebyField('id', $id);

                if($product->type == 'configurable') {
                    $variant = $this->product->findOneByField('id', $data['selected_configurable_option']);

                    if($item->child->product_id == $data['selected_configurable_option']) {
                        return $item->id;
                    }
                } else {
                    return $item->id;
                }
            }
        }

        return 0;
    }

    /**
     * Create the item based on the type of product whether simple or configurable
     *
     * @return Mixed Array $item || Error
     */
    public function createItem($id, $data)
    {
        $product = $parentProduct = $configurable = false;
        $product = $this->product->findOneByField('id', $id);

        if($product->type == 'configurable') {
            if(!isset($data['selected_configurable_option'])) {
                return false;
            }

            $parentProduct = $this->product->findOneByField('id', $data['selected_configurable_option']);

            $canAdd = $parentProduct->haveSufficientQuantity($data['quantity']);

            if(!$canAdd) {
                session()->flash('warning', 'insuff qty');

                return false;
            }

            $configurable = true;
        } else {
            $canAdd = $product->haveSufficientQuantity($data['quantity']);

            if(!$canAdd) {
                session()->flash('warning', 'insuff qty');

                return false;
            }
        }

        //Check if the product's information is proper or not
        if(!isset($data['product']) || !isset($data['quantity'])) {
            session()->flash('error', trans('shop::app.checkout.cart.integrity.missing_fields'));

            return false;
        } else {
            if($product->type == 'configurable' && !isset($data['super_attribute'])) {
                session()->flash('error', trans('shop::app.checkout.cart.integrity.missing_options'));

                return false;
            }
        }

        $child = $childData = null;
        $additional = [];
        $price = ($product->type == 'configurable' ? $parentProduct->price : $product->price);
        $weight = ($product->type == 'configurable' ? $parentProduct->weight : $product->weight);

        $parentData = [
            'sku' => $product->sku,
            'quantity' => $data['quantity'],
            'cart_id' => $this->getCart()->id,
            'name' => $product->name,
            'price' => core()->convertPrice($price),
            'base_price' => $price,
            'total' => core()->convertPrice($price * $data['quantity']),
            'base_total' => $price * $data['quantity'],
            'weight' => $weight,
            'total_weight' => $weight * $data['quantity'],
            'base_total_weight' => $weight * $data['quantity'],
            'additional' => $additional
        ];

        if($configurable){
            $parentData['type'] = $product['type'];
            $parentData['product_id'] = $product['id'];
            $parentData['additional'] = $data;
        } else {
            $parentData['type'] = $product['type'];
            $parentData['product_id'] = $product['id'];
            $parentData['additional'] = $data;
        }

        if($configurable) {
            $additional = $this->getProductAttributeOptionDetails($parentProduct);

            unset($additional['html']);

            $additional['request'] = $data;
            $additional['variant_id'] = $data['selected_configurable_option'];

            $childData['product_id'] = (int)$data['selected_configurable_option'];
            $childData['sku'] = $parentProduct->sku;
            $childData['name'] = $parentProduct->name;
            $childData['type'] = 'simple';
            $childData['cart_id'] = $this->getCart()->id;
        }

        $result = $this->cartItem->create($parentData);

        if ($childData != null) {
            $childData['parent_id'] = $result->id;
            $this->cartItem->create($childData);
        }

        if($result)
            return true;
        else
            return false;
    }

    /**
     * Update the cartItem on cart checkout page and if already added item is added again
     * @param $id product_id of cartItem instance
     * @param $data new requested quantities by customer
     * @param $itemId is id from cartItem instance
     * @return boolean
     */
    public function updateItem($id, $data, $itemId)
    {
        $item = $this->cartItem->findOneByField('id', $itemId);

        if($item->type == 'configurable') {
            $product = $this->product->findOneByField('id', $item->child->product_id);

            if(!$product->haveSufficientQuantity($data['quantity'])) {
                session()->flash('warning', trans('shop::app.checkout.cart.quantity.inventory_warning'));

                return false;
            }
        } else {
            $product = $this->product->findOneByField('id', $item->product_id);

            if(!$product->haveSufficientQuantity($data['quantity'])) {
                session()->flash('warning', trans('shop::app.checkout.cart.quantity.inventory_warning'));

                return false;
            }
        }

        $quantity = $data['quantity'];

        $result = $item->update([
            'quantity' => $quantity,
            'total' => core()->convertPrice($item->price * ($quantity)),
            'base_total' => $item->price * ($quantity),
            'total_weight' => $item->weight * ($quantity),
            'base_total_weight' => $item->weight * ($quantity)
        ]);

        $this->collectTotals();

        if($result) {
            session()->flash('success', trans('shop::app.checkout.cart.quantity.success'));

            return true;
        } else {
            session()->flash('warning', trans('shop::app.checkout.cart.quantity.error'));

            return false;
        }
    }

    /**
     * Remove the item from the cart
     *
     * @return response
     */
    public function removeItem($itemId)
    {
        if($cart = $this->getCart()) {
            $this->cartItem->delete($itemId);

            //delete the cart instance if no items are there
            if($cart->items()->get()->count() == 0) {
                $this->cart->delete($cart->id);

                // $this->deActivateCart();
                if(session()->has('cart')) {
                    session()->forget('cart');
                }
            }

            session()->flash('success', trans('shop::app.checkout.cart.item.success-remove'));

            return true;
        }

        return false;
    }

    /**
     * This function handles when guest has some of cart products and then logs in.
     *
     * @return Response
     */
    public function mergeCart()
    {
        if(session()->has('cart')) {
            $cart = $this->cart->findWhere(['customer_id' => auth()->guard('customer')->user()->id, 'is_active' => 1]);

            if($cart->count()) {
                $cart = $cart->first();
            } else {
                $cart = false;
            }

            $guestCart = session()->get('cart');

            //when the logged in customer is not having any of the cart instance previously and are active.
            if(!$cart) {
                $guestCart->update([
                    'customer_id' => auth()->guard('customer')->user()->id,
                    'is_guest' => 0,
                    'customer_first_name' => auth()->guard('customer')->user()->first_name,
                    'customer_last_name' => auth()->guard('customer')->user()->last_name,
                    'customer_email' => auth()->guard('customer')->user()->email
                ]);

                session()->forget('cart');

                return true;
            }

            $cartItems = $cart->items;

            $guestCartId = $guestCart->id;

            $guestCartItems = $this->cart->findOneByField('id', $guestCartId)->items;

            foreach($guestCartItems as $key => $guestCartItem) {
                foreach($cartItems as $cartItem) {

                    if($guestCartItem->type == "simple") {
                        if($cartItem->product_id == $guestCartItem->product_id) {
                            $prevQty = $cartItem->quantity;
                            $newQty = $guestCartItem->quantity;

                            $product = $this->product->findOneByField('id', $cartItem->product_id);

                            if(!$product->haveSufficientQuantity($prevQty + $newQty)) {
                                $this->cartItem->delete($guestCartItem->id);
                                continue;
                            }

                            $data['quantity'] = $newQty + $prevQty;

                            $this->updateItem($cartItem->product_id, $data, $cartItem->id);

                            $guestCartItems->forget($key);
                            $this->cartItem->delete($guestCartItem->id);
                        }
                    } else if($guestCartItem->type == "configurable" && $cartItem->type == "configurable") {
                        $guestCartItemChild = $guestCartItem->child;

                        $cartItemChild = $cartItem->child;

                        if($guestCartItemChild->product_id == $cartItemChild->product_id) {
                            $prevQty = $guestCartItem->quantity;
                            $newQty = $cartItem->quantity;

                            $product = $this->product->findOneByField('id', $cartItem->child->product_id);

                            if(!$product->haveSufficientQuantity($prevQty + $newQty)) {
                                $this->cartItem->delete($guestCartItem->id);
                                continue;
                            }

                            $data['quantity'] = $newQty + $prevQty;

                            $this->updateItem($cartItem->product_id, $data, $cartItem->id);

                            $guestCartItems->forget($key);

                            $this->cartItem->delete($guestCartItem->id);
                        }
                    }
                }
            }

            //now handle the products that are not removed from the list of items in the guest cart.
            foreach($guestCartItems as $guestCartItem) {

                if($guestCartItem->type == "configurable") {
                    $guestCartItem->update(['cart_id' => $cart->id]);

                    $guestCartItem->child->update(['cart_id' => $cart->id]);
                } else{
                    $guestCartItem->update(['cart_id' => $cart->id]);
                }
            }

            //delete the guest cart instance.
            $this->cart->delete($guestCartId);

            //forget the guest cart instance
            session()->forget('cart');

            $this->collectTotals();

            return true;
        } else {
            return true;
        }
    }

    /**
     * Save cart
     *
     * @return mixed
     */
    public function putCart($cart)
    {
        if(!auth()->guard('customer')->check()) {
            session()->put('cart', $cart);
        }
    }

    /**
     * Returns cart
     *
     * @return mixed
     */
    public function getCart()
    {
        $cart = null;

        if (auth()->guard('customer')->check()) {
            $cart = $this->cart->findOneWhere([
                'customer_id' => auth()->guard('customer')->user()->id,
                'is_active' => 1
            ]);

        } elseif (session()->has('cart')) {
            $cart = $this->cart->find(session()->get('cart')->id);
        }

        // if($cart != null) {
        //     if($cart->items->count() == 0) {
        //         $this->cart->delete($cart->id);

        //         return false;
        //     }
        // }

        return $cart && $cart->is_active ? $cart : null;
    }

    /**
     * Returns cart details in array
     *
     * @return array
     */
    public function toArray()
    {
        $cart = $this->getCart();

        $data = $cart->toArray();

        $data['shipping_address'] = current($data['shipping_address']);

        $data['billing_address'] = current($data['billing_address']);

        $data['selected_shipping_rate'] = $cart->selected_shipping_rate->toArray();

        return $data;
    }

    /**
     * Returns the items details of the configurable and simple products
     *
     * @return Mixed
     */
    public function getProductAttributeOptionDetails($product)
    {
        $data = [];

        $labels = [];

        $attribute = $product->parent->super_attributes;
        foreach($product->parent->super_attributes as $attribute) {
            $option = $attribute->options()->where('id', $product->{$attribute->code})->first();

            $data['attributes'][$attribute->code] = [
                'attribute_name' => $attribute->name,
                'option_id' => $option->id,
                'option_label' => $option->label,
            ];

            $labels[] = $attribute->name . ' : ' . $option->label;
        }

        $data['html'] = implode(', ', $labels);

        return $data;
    }

    /**
     * Save customer address
     *
     * @return Mixed
     */
    public function saveCustomerAddress($data)
    {
        if(!$cart = $this->getCart())
            return false;

        $billingAddress = $data['billing'];
        $shippingAddress = $data['shipping'];
        $billingAddress['cart_id'] = $shippingAddress['cart_id'] = $cart->id;

        if($billingAddressModel = $cart->billing_address) {
            $this->cartAddress->update($billingAddress, $billingAddressModel->id);

            if($shippingAddressModel = $cart->shipping_address) {
                if(isset($billingAddress['use_for_shipping']) && $billingAddress['use_for_shipping']) {
                    $this->cartAddress->update($billingAddress, $shippingAddressModel->id);
                } else {
                    $this->cartAddress->update($shippingAddress, $shippingAddressModel->id);
                }
            } else {
                if(isset($billingAddress['use_for_shipping']) && $billingAddress['use_for_shipping']) {
                    $this->cartAddress->create(array_merge($billingAddress, ['address_type' => 'shipping']));
                } else {
                    $this->cartAddress->create(array_merge($shippingAddress, ['address_type' => 'shipping']));
                }
            }
        } else {
            $this->cartAddress->create(array_merge($billingAddress, ['address_type' => 'billing']));

            if(isset($billingAddress['use_for_shipping']) && $billingAddress['use_for_shipping']) {
                $this->cartAddress->create(array_merge($billingAddress, ['address_type' => 'shipping']));
            } else {
                $this->cartAddress->create(array_merge($shippingAddress, ['address_type' => 'shipping']));
            }
        }

        if(auth()->guard('customer')->check()) {
            $cart->customer_email = auth()->guard('customer')->user()->email;
            $cart->customer_first_name = auth()->guard('customer')->user()->first_name;
            $cart->customer_last_name = auth()->guard('customer')->user()->last_name;
        } else {
            $cart->customer_email = $cart->billing_address->email;
            $cart->customer_first_name = $cart->billing_address->first_name;
            $cart->customer_last_name = $cart->billing_address->last_name;
        }

        $cart->save();

        return true;
    }

    /**
     * Save shipping method for cart
     *
     * @param string $shippingMethodCode
     * @return Mixed
     */
    public function saveShippingMethod($shippingMethodCode)
    {
        if(!$cart = $this->getCart())
            return false;

        $cart->shipping_method = $shippingMethodCode;
        $cart->save();

        // foreach($cart->shipping_rates as $rate) {
        //     if($rate->method != $shippingMethodCode) {
        //         $rate->delete();
        //     }
        // }

        return true;
    }

    /**
     * Save payment method for cart
     *
     * @param string $payment
     * @return Mixed
     */
    public function savePaymentMethod($payment)
    {
        if(!$cart = $this->getCart())
            return false;

        if($cartPayment = $cart->payment)
            $cartPayment->delete();

        $cartPayment = new CartPayment;

        $cartPayment->method = $payment['method'];
        $cartPayment->cart_id = $cart->id;
        $cartPayment->save();

        return $cartPayment;
    }

    /**
     * Updates cart totals
     *
     * @return void
     */
    public function collectTotals()
    {
        $validated = $this->validateItems();

        if(!$validated) {
            return false;
        }

        if(!$cart = $this->getCart())
            return false;

        $this->calculateItemsTax();

        $cart->grand_total = $cart->base_grand_total = 0;
        $cart->sub_total = $cart->base_sub_total = 0;
        $cart->tax_total = $cart->base_tax_total = 0;

        foreach ($cart->items()->get() as $item) {
            $cart->grand_total = (float) $cart->grand_total + $item->total + $item->tax_amount;
            $cart->base_grand_total = (float) $cart->base_grand_total + $item->base_total + $item->base_tax_amount;

            $cart->sub_total = (float) $cart->sub_total + $item->total;
            $cart->base_sub_total = (float) $cart->base_sub_total + $item->base_total;

            $cart->tax_total = (float) $cart->tax_total + $item->tax_amount;
            $cart->base_tax_total = (float) $cart->base_tax_total + $item->base_tax_amount;
        }

        if($shipping = $cart->selected_shipping_rate) {
            $cart->grand_total = (float) $cart->grand_total + $shipping->price;
            $cart->base_grand_total = (float) $cart->base_grand_total + $shipping->base_price;
        }

        $quantities = 0;
        foreach($cart->items as $item) {
            $quantities = $quantities + $item->quantity;
        }

        $cart->items_count = $cart->items->count();

        $cart->items_qty = $quantities;

        $cart->save();
    }

    /**
     * To validate if the product information is changed by admin and the items have
     * been added to the cart before it.
     *
     * @return boolean
     */
    public function validateItems()
    {
        $cart = $this->getCart();

        if(!$cart) {
            return false;
        }

        //rare case of accident-->used when there are no items.
        if(count($cart->items) == 0) {
            $this->cart->delete($cart->id);

            return false;
        } else {
            $items = $cart->items;

            foreach($items as $item) {
                if($item->product->type == 'configurable') {
                    if($item->product->sku != $item->sku) {
                        $item->update(['sku' => $item->product->sku]);

                    } else if($item->product->name != $item->name) {
                        $item->update(['name' => $item->product->name]);

                    } else if($item->child->product->price != $item->price) {
                        $item->update([
                            'price' => $item->child->product->price,
                            'base_price' => $item->child->product->price,
                            'total' => core()->convertPrice($item->child->product->price * ($item->quantity)),
                            'base_total' => $item->child->product->price * ($item->quantity),
                        ]);
                    }

                } else if($item->product->type == 'simple') {
                    if($item->product->sku != $item->sku) {
                        $item->update(['sku' => $item->product->sku]);

                    } else if($item->product->name != $item->name) {
                        $item->update(['name' => $item->product->name]);

                    } else if($item->product->price != $item->price) {
                        $item->update([
                            'price' => $item->product->price,
                            'base_price' => $item->product->price,
                            'total' => core()->convertPrice($item->product->price * ($item->quantity)),
                            'base_total' => $item->product->price * ($item->quantity),
                        ]);
                    }
                }
            }
            return true;
        }
    }

    /**
     * Calculates cart items tax
     *
     * @return void
    */
    public function calculateItemsTax()
    {
        $cart = $this->getCart();

        if(!$shippingAddress = $cart->shipping_address)
            return;

        foreach ($cart->items()->get() as $item) {
            $taxCategory = $this->taxCategory->find($item->product->tax_category_id);

            if(!$taxCategory)
                continue;

            $taxRates = $taxCategory->tax_rates()->where([
                    'state' => $shippingAddress->state,
                    'country' => $shippingAddress->country,
                ])->orderBy('tax_rate', 'desc')->get();

            foreach($taxRates as $rate) {
                $haveTaxRate = false;

                if(!$rate->is_zip) {
                    if($rate->zip_code == '*' || $rate->zip_code == $shippingAddress->postcode) {
                        $haveTaxRate = true;
                    }
                } else {
                    if($shippingAddress->postcode >= $rate->zip_from && $shippingAddress->postcode <= $rate->zip_to) {
                        $haveTaxRate = true;
                    }
                }

                if($haveTaxRate) {
                    $item->tax_percent = $rate->tax_rate;
                    $item->tax_amount = ($item->total * $rate->tax_rate) / 100;
                    $item->base_tax_amount = ($item->base_total * $rate->tax_rate) / 100;

                    $item->save();
                    break;
                }
            }
        }
    }

    /**
     * Checks if cart has any error
     *
     * @return boolean
     */
    public function hasError()
    {
        if(!$this->getCart())
            return true;

        if(!$this->isItemsHaveSufficientQuantity())
            return true;

        return false;
    }

    /**
     * Checks if all cart items have sufficient quantity.
     *
     * @return boolean
     */
    public function isItemsHaveSufficientQuantity()
    {
        foreach ($this->getCart()->items as $item) {
            if(!$this->isItemHaveQuantity($item))
                return false;
        }

        return true;
    }

    /**
     * Checks if all cart items have sufficient quantity.
     *
     * @return boolean
     */
    public function isItemHaveQuantity($item)
    {
        $product = $item->type == 'configurable' ? $item->child->product : $item->product;

        if(!$product->haveSufficientQuantity($item->quantity))
            return false;

        return true;
    }

    /**
     * Deactivates current cart
     *
     * @return void
     */
    public function deActivateCart()
    {
        if($cart = $this->getCart()) {
            $this->cart->update(['is_active' => false], $cart->id);

            if(session()->has('cart')) {
                session()->forget('cart');
            }
        }
    }

    /**
     * Validate order before creation
     *
     * @return array
     */
    public function prepareDataForOrder()
    {
        $data = $this->toArray();

        $finalData = [
            'cart_id' => $this->getCart()->id,

            'customer_id' => $data['customer_id'],
            'is_guest' => $data['is_guest'],
            'customer_email' => $data['customer_email'],
            'customer_first_name' => $data['customer_first_name'],
            'customer_last_name' => $data['customer_last_name'],
            'customer' => auth()->guard('customer')->check() ? auth()->guard('customer')->user() : null,

            'shipping_method' => $data['selected_shipping_rate']['method'],
            'shipping_title' => $data['selected_shipping_rate']['carrier_title'] . ' - ' . $data['selected_shipping_rate']['method_title'],
            'shipping_description' => $data['selected_shipping_rate']['method_description'],
            'shipping_amount' => $data['selected_shipping_rate']['price'],
            'base_shipping_amount' => $data['selected_shipping_rate']['base_price'],

            'total_item_count' => $data['items_count'],
            'total_qty_ordered' => $data['items_qty'],
            'base_currency_code' => $data['base_currency_code'],
            'channel_currency_code' => $data['channel_currency_code'],
            'order_currency_code' => $data['cart_currency_code'],
            'grand_total' => $data['grand_total'],
            'base_grand_total' => $data['base_grand_total'],
            'sub_total' => $data['sub_total'],
            'base_sub_total' => $data['base_sub_total'],
            'tax_amount' => $data['tax_total'],
            'base_tax_amount' => $data['base_tax_total'],

            'shipping_address' => array_except($data['shipping_address'], ['id', 'cart_id']),
            'billing_address' => array_except($data['billing_address'], ['id', 'cart_id']),
            'payment' => array_except($data['payment'], ['id', 'cart_id']),

            'channel' => core()->getCurrentChannel(),
        ];

        foreach($data['items'] as $item) {
            $finalData['items'][] = $this->prepareDataForOrderItem($item);
        }

        return $finalData;
    }

    /**
     * Prepares data for order item
     *
     * @return array
     */
    public function prepareDataForOrderItem($data)
    {
        $finalData = [
            'product' => $this->product->find($data['product_id']),
            'sku' => $data['sku'],
            'type' => $data['type'],
            'name' => $data['name'],
            'weight' => $data['weight'],
            'total_weight' => $data['total_weight'],
            'qty_ordered' => $data['quantity'],
            'price' => $data['price'],
            'base_price' => $data['base_price'],
            'total' => $data['total'],
            'base_total' => $data['base_total'],
            'tax_percent' => $data['tax_percent'],
            'tax_amount' => $data['tax_amount'],
            'base_tax_amount' => $data['base_tax_amount'],
            'additional' => $data['additional'],
        ];

        if(isset($data['child']) && $data['child']) {
            $finalData['child'] = $this->prepareDataForOrderItem($data['child']);
        }

        return $finalData;
    }

    /**
     * Move to Cart
     *
     * Move a wishlist item to cart
     */
    public function moveToCart($wishlistItem) {
        $product = $wishlistItem->product;

        if($product->type == 'simple') {
            $data['quantity'] = 1;
            $data['product'] = $wishlistItem->product->id;

            $result = $this->add($product->id, $data);

            if($result) {
                return 1;
            } else {
                return 0;
            }
        } else if($product->type == 'configurable' && $product->parent_id == null) {
            return -1;
        }
    }

    /**
     * Function to move a already added product to wishlist will run only on customer authentication.
     *
     * @param instance cartItem $id
     */
    public function moveToWishlist($itemId) {
        $cart = $this->getCart();
        $items = $cart->items;
        $wishlist = [];
        $wishlist = [
            'channel_id' => $cart->channel_id,
            'customer_id' => auth()->guard('customer')->user()->id,
        ];

        foreach($items as $item) {
            if($item->id == $itemId) {
                if(is_null($item['parent_id']) && $item['type'] == 'simple') {
                    $wishlist['product_id'] = $item->product_id;
                } else {
                    $wishlist['product_id'] = $item->child->product_id;
                    $wishtlist['options'] = $item->additional;
                }

                $shouldBe = $this->wishlist->findWhere(['customer_id' => auth()->guard('customer')->user()->id, 'product_id' => $wishlist['product_id']]);

                if($shouldBe->isEmpty()) {
                    $wishlist = $this->wishlist->create($wishlist);
                }

                $result = $this->cartItem->delete($itemId);

                if($result) {
                    if($cart->items()->count() == 0)
                        $this->cart->delete($cart->id);

                    session()->flash('success', trans('shop::app.checkout.cart.move-to-wishlist-success'));

                    return $result;
                } else {
                    session()->flash('success', trans('shop::app.checkout.cart.move-to-wishlist-error'));

                    return $result;
                }
            }
        }
    }

    /**
     * Handle the buy now process for simple as well as configurable products
     *
     * @return response mixed
     */
    public function proceedToBuyNow($id) {
        $product = $this->product->findOneByField('id', $id);

        if($product->type == 'configurable') {
            session()->flash('warning', trans('shop::app.buynow.no-options'));

            return false;
        } else {
            $simpleOrVariant = $this->product->find($id);

            if($simpleOrVariant->parent_id != null) {
                $parent = $simpleOrVariant->parent;

                $data['product'] = $parent->id;
                $data['selected_configurable_option'] = $simpleOrVariant->id;
                $data['quantity'] = 1;
                $data['super_attribute'] = 'From Buy Now';

                $result = $this->add($parent->id, $data);

                return $result;
            } else {
                $data['product'] = $id;
                $data['is_configurable'] = false;
                $data['quantity'] = 1;

                $result = $this->add($id, $data);

                return $result;
            }
        }
    }
}