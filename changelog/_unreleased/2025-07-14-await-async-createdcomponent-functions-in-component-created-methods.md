---
title: Await async `createdComponent` functions in component `created` methods
author: Max
author_email: max@swk-web.com
author_github: @aragon999
---
# Administration
* Changed components `sw-product-detail`, `sw-sales-channel-detail-domains`, `sw-sales-channel-measurement` and `sw-settings-measurement` to have async `created` methods and `await` the `createdComponent()` method call to make overwriting of these components possible
