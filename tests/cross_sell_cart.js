const fs = require('fs');
const vm = require('vm');
const assert = require('assert');
const source = fs.readFileSync(require('path').join(__dirname, '../assets/js/PosJS.js'), 'utf8');
const functions = source.slice(source.indexOf('function getPairingQuantity('), source.indexOf('function updateCartDisplay('));
const target = {id: 1, price: 220, quantity: 3, promotion: {
    strategy_id: 'cross_sell_pairing', paired_product_id: 2, discounted_price: 198
}};
const partner = {id: 2, price: 50, quantity: 1};
const context = {cart: [target]};
vm.createContext(context);
vm.runInContext(functions, context);
assert.equal(context.getCartLineTotal(target), 660);
context.cart.push(partner);
assert.equal(context.getCartLineTotal(target), 638);
partner.quantity = 5;
assert.equal(context.getCartLineTotal(target), 594);
context.cart.pop();
assert.equal(context.getCartLineTotal(target), 660);
context.cart.push(partner);
target.promotion.strategy_ended_at = '2000-01-01 00:00:00';
assert.equal(context.getCartLineTotal(target), 660);
target.promotion = {};
assert.equal(context.getCartLineTotal(target), 660);
console.log('PASS: cart pairing, quantity changes, partner removal, expiration and cancellation');
