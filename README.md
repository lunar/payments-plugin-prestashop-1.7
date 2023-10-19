# Lunar Online Payments for Prestashop
The software is provided “as is”, without warranty of any kind, express or implied, including but not limited to the warranties of merchantability, fitness for a particular purpose and noninfringement.

## Supported Prestashop versions

*The plugin has been tested with most versions of Prestashop at every iteration. We recommend using the latest version of Prestashop, but if that is not possible for some reason, test the plugin with your Prestashop version and it would probably function properly.*

## Installation
Once you have installed Prestashop, follow these simple steps:
1. Signup at [lunar.app](https://lunar.app) (it’s free)
1. Create an account
1. Create an app key for your Prestashop website
1. Log in as administrator and click "Modules" from the left menu and then upload it clicking "UPLOAD A MODULE" form the top.
2. Click the "Configure" button when done installing.
3. Add the App and Public keys that you can find in your Lunar account and save the changes.

## Updating settings
Under the extension settings, you can:
 * Update the payment method title in the payment gateways list
 * Update the payment method description in the payment gateways list
 * Update the logo url for the logo that shows up in the hosted checkout page
 * Update app & public keys
 * Change the capture type (Instant/Manual via Lunar Tool)
 * Change the status of the order which is going to trigger a capture in delayed mode.


 ## Capture - Refund - Cancel
 * To `Capture` an order in delayed mode, use the status set in Lunar module settings (move the order to that status).
 * To `Refund` an order make sure you checked the "Refund Lunar" checkbox during the default Prestashop procedure for Partial Refund. Standard Refund and Return Product procedures also have this feature only for Prestashop version >= 1.7.7.
    - Note: If for some reason the Refund procedure via Lunar fails, you will be notified and manual action will be required in your online Lunar account.
 * To `Cancel` an order move the order status to "Canceled".

 * For Prestashop < 1.7.7 you can proceed capture, refund and cancel actions via Lunar Toolbox also.

 ## Changelog
#### 2.1.0:
- Fixed rounding refund value & removed test fields
#### 2.0.0:
- Migrated to hosted checkout flow
#### 1.0.0:
- Initial commit
