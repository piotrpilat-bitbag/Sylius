@worker
Feature: Request isolation in worker mode
    In worker mode one PHP process serves many requests in sequence. A shared
    service that remembers something from request A hands it to request B - and
    request B may belong to a different channel, or to a different customer.

    These scenarios pass in classic mode by construction, because there every
    request gets a fresh process. They only mean something against a worker.

    The two channels - different names, hostnames, locales and currencies - are
    set up by the `worker` fixtures suite, loaded before the worker starts.
    There is no Given step for it on purpose: the setup has to happen before the
    process under test comes up.

    Scenario: A channel renders its own name, whichever one was served first
        When I browse "/" alternating between both channels 2 times
        Then channel "A" renders its own name
        And channel "B" renders its own name

    Scenario: Adding a product to the cart works at all
        When I add the first product to the cart on channel "A"
        Then every request succeeded

    Scenario: Two customers browsing alternately each see their own profile
        When visitor "1" registers on channel "A"
        And visitor "2" registers on channel "A"
        And visitors "1" and "2" browse alternately on channel "A" 4 times
        Then visitor "1" sees only their own personal data on channel "A"
        And visitor "2" sees only their own personal data on channel "A"

    Scenario: The same, across two channels
        When visitor "1" registers on channel "A"
        And visitor "2" registers on channel "B"
        And visitors "1" and "2" browse alternately on channel "A" 3 times
        Then visitor "1" sees only their own personal data on channel "A"
        And visitor "2" sees only their own personal data on channel "B"

    Scenario: A third customer arriving on a warmed-up worker
        When visitor "1" registers on channel "A"
        And visitor "2" registers on channel "A"
        And visitors "1" and "2" browse alternately on channel "A" 3 times
        And visitor "3" registers on channel "A"
        Then visitor "3" sees only their own personal data on channel "A"
        And visitor "1" sees only their own personal data on channel "A"

    #  --- browsing both channels alternately -------------------------------

    Scenario: The homepage alternating between both channels
        When I browse "/" alternating between both channels 3 times
        Then every request succeeded

    Scenario: A product page on channel A, then channel B
        When I open the first product on channel "A"
        And I visit "/" on channel "B"
        And I open the first product on channel "B"
        Then every request succeeded

    #  --- the cart ---------------------------------------------------------

    Scenario: A shopping session on channel A, then traffic on channel B
        When I start a shopping session on channel "A"
        And I visit "/" on channel "B"
        And I open the first product on channel "B"
        Then every request succeeded

    Scenario: The cart visited alternately from both channels
        When I start a shopping session on channel "A"
        And I visit "/" on channel "B"
        And I open the cart on channel "A"
        And I visit "/" on channel "B"
        Then every request succeeded

    #  --- customer account -------------------------------------------------

    Scenario: Registration, the account panel, then the other channel
        When visitor "1" registers on channel "A"
        Then channel "A" sees the customer I registered
        When I browse my account on channel "A"
        And I visit "/" on channel "B"
        Then every request succeeded

    Scenario: Two customers in sequence on the same channel
        When visitor "1" registers on channel "A"
        And I browse my account on channel "A"
        And a new visitor arrives
        And visitor "2" registers on channel "A"
        Then channel "A" sees the customer I registered

    #  --- the back office --------------------------------------------------

    Scenario: Back-office login, then shop traffic
        When I log into the admin panel
        And I visit "/" on channel "A"
        And I visit "/" on channel "B"
        Then every request succeeded

    Scenario: Creating an administrator does not break editing one without a password
        When I log into the admin panel
        And I create a new administrator in the admin panel
        Then I can edit that administrator without entering a password

    #  This one needs a fix in sylius/resource-bundle, not in this repository.
    #  ResourceTranslationsType resolves the locale list in its constructor and
    #  passes it to OptionsResolver as a static array, while ResolvedFormType
    #  calls configureOptions once per worker - so a newly added language never
    #  shows up in translation forms until the process restarts.
    #
    #  The job pulls resource-bundle from the fork branch (RESOURCE_BUNDLE_REF),
    #  so the scenario passes. Against the released package it fails, which is
    #  what a regression test should do.
    Scenario: A locale added in the admin panel shows up in translation forms
        When I log into the admin panel
        And I open the new taxon form in the admin panel
        And I add a new locale in the admin panel
        Then the new taxon form includes a translation field for that locale

    #  --- the shop API -----------------------------------------------------

    Scenario: Products from the API on both channels
        When I request the shop API "/api/v2/shop/products" on channel "A"
        And I request the shop API "/api/v2/shop/products" on channel "B"
        And I request the shop API "/api/v2/shop/products" on channel "A"
        Then every request succeeded

    Scenario: Creating a cart via the API negotiates the channel's own locale
        When I visit "/" on channel "A"
        And I create a cart via the shop API on channel "B" with Accept-Language "pl-PL,pl;q=0.9,en-US;q=0.8"
        Then the API cart has locale "pl_PL"

    Scenario: A shop cart does not crash a subsequent JWT API order request
        When I start a shopping session on channel "A"
        And a new visitor arrives
        And I authenticate via the shop API as "shop@example.com" with password "sylius" on channel "A"
        And I request my orders via the shop API on channel "A"
        Then every request succeeded

    Scenario: A JWT cart after an anonymous shop visit does not leak to the next API client
        When I start a shopping session on channel "A"
        And a new visitor arrives
        And I authenticate via the shop API as "shop@example.com" with password "sylius" on channel "A"
        And I create a cart via the shop API on channel "A"
        Then the API cart belongs to customer "shop@example.com"
        When a new visitor arrives
        And I create a cart via the shop API on channel "B"
        Then the API cart belongs to no customer
        And every request succeeded

    #  --- privileges: an admin next to a shopper ---------------------------

    Scenario: An admin and a customer alternating, each with their own privileges
        When visitor "admin" logs into the admin panel
        And visitor "customer" registers on channel "A"
        And visitors "admin" and "customer" alternate admin and shop on channel "A" 4 times
        Then visitor "customer" cannot reach the admin panel
        And visitor "admin" still has the admin panel
        And visitor "customer" sees only their own personal data on channel "A"

    Scenario: An anonymous visitor after an admin does not inherit the back office
        When visitor "admin" logs into the admin panel
        And visitor "anonymous" visits "/" on channel "A"
        Then visitor "anonymous" cannot reach the admin panel

    #  --- customer identity across channels --------------------------------

    Scenario: A customer login on channel A is visible only on channel A
        When I log in as customer "shop@example.com" with password "sylius" on channel "A"
        Then channel "A" sees customer "shop@example.com"
        And channel "B" sees no logged-in customer

    Scenario: A logged-in customer's cart, then traffic on the other channel
        When I log in as customer "shop@example.com" with password "sylius" on channel "A"
        And I open the cart on channel "A"
        And I visit "/" on channel "B"
        Then every request succeeded

    #  --- paths flagged by the static scan ---------------------------------

    Scenario: The channel code validator does not cache codes across channels
        When I log into the admin panel
        And I visit "/admin/channels" on channel "A"
        And a new visitor arrives
        And I log into the admin panel
        And I visit "/admin/channels" on channel "B"
        Then every request succeeded

    Scenario: The product listing alternating between both channels
        When I open the first category on channel "A"
        And I open the first category on channel "B"
        And I open the first category on channel "A"
        Then every request succeeded

    Scenario: Interleaving two customers across two channels
        When visitor "1" registers on channel "A"
        And visitor "2" registers on channel "B"
        And visitors "1" and "2" browse alternately on channel "A" 3 times
        Then every request succeeded

    #  --- checkout ---------------------------------------------------------

    Scenario: A full checkout on channel A, then traffic on channel B
        When I complete the checkout on channel "A"
        And I visit "/" on channel "B"
        Then every request succeeded

    Scenario: A customer creates an account and places an order
        When visitor "1" registers on channel "A"
        And I complete the checkout on channel "A"
        And I browse my account on channel "A"
        Then every request succeeded

    Scenario: A checkout, then a new visitor on the same channel
        When I complete the checkout on channel "A"
        And a new visitor arrives
        And I visit "/" on channel "A"
        Then every request succeeded
