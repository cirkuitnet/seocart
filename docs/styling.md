# Styling the storefront

> **Experimental until 1.0.** The variables and the class names below are the storefront's styling
> interface. Until version 1.0 a name may still change in a minor release; from 1.0 on, renaming
> or removing one is a breaking change.

The storefront blocks print markup with stable `seocart-*` class names and style it with one
stylesheet, `build/blocks/storefront.css`, which a page loads only when it renders a storefront
block. Every colour, font, radius and size in that stylesheet is a `--seocart-*` custom property.
To restyle the storefront, set the variables; you should not need to override a selector.

## Variables

Each variable is declared once, on `:where( :root )`. That selector has no specificity, so any
rule of the theme, a Global Styles setting or the merchant's Additional CSS that sets the variable
wins. A variable that stands for a `theme.json` preset takes the preset's value; a theme without
the preset gets the literal value named after it.

The error, success and focus colours are fixed hues mixed 70 % with the text colour. On a light
theme the mix darkens them and on a dark theme it lightens them: against a white page with
near-black text, and against a near-black page with near-white text, each keeps a contrast of at
least 4.5:1, which none of the three fixed hues keeps on the dark page by itself.

| Variable                        | Source                                                        | Use                                                                             |
| ------------------------------- | ------------------------------------------------------------- | ------------------------------------------------------------------------------- |
| `--seocart-color-text`          | `--wp--preset--color--contrast`, else `currentcolor`          | Text, and the base of every mixed colour                                        |
| `--seocart-color-bg`            | `--wp--preset--color--base`, else `canvas`                    | The background the colours are mixed toward                                     |
| `--seocart-color-muted`         | 62 % text over background                                     | Secondary text: the selected value beside an axis, the price before a reduction |
| `--seocart-color-border`        | 16 % text over background                                     | Light borders: a notice, a swatch                                               |
| `--seocart-color-border-strong` | 34 % text over background                                     | Control borders: a pill, the quantity                                           |
| `--seocart-color-surface`       | 4 % text over background                                      | A notice's background, the reserved price skeleton                              |
| `--seocart-color-field`         | `--seocart-color-bg`                                          | The background of a pill and of the quantity                                    |
| `--seocart-color-primary`       | `--wp--preset--color--contrast`, else `currentcolor`          | The add button, a selected pill                                                 |
| `--seocart-color-on-primary`    | `--wp--preset--color--base`, else `canvas`                    | Text on the primary colour                                                      |
| `--seocart-color-link`          | `--wp--preset--color--contrast`, else `currentcolor`          | Links                                                                           |
| `--seocart-color-focus`         | `#1a73e8` mixed 70 % with the text colour                     | The focus ring                                                                  |
| `--seocart-color-error`         | `#c5221f` mixed 70 % with the text colour                     | An error's text and border                                                      |
| `--seocart-color-error-bg`      | 8 % error over background                                     | An error's background                                                           |
| `--seocart-color-success`       | `#137333` mixed 70 % with the text colour                     | A success's text                                                                |
| `--seocart-font-body`           | `--wp--preset--font-family--body`, else the inherited font    | Controls and buttons                                                            |
| `--seocart-font-heading`        | `--wp--preset--font-family--heading`, else the inherited font | Headings                                                                        |
| `--seocart-heading-weight`      | `--wp--custom--heading-weight`, else `600`                    | Labels, legends, the price, the add button                                      |
| `--seocart-radius`              | `--wp--custom--radius`, else `4px`                            | Fields, notices, the quantity                                                   |
| `--seocart-radius-button`       | `--wp--custom--button-radius`, else `4px`                     | Buttons and pills                                                               |
| `--seocart-control-height`      | `48px`                                                        | The height of controls; never below 24 px                                       |
| `--seocart-gap`                 | `16px`                                                        | The space between and inside components                                         |
| `--seocart-section-gap`         | `32px`                                                        | The space between sections of a page                                            |
| `--seocart-summary-width`       | `420px`                                                       | The width of an order summary column                                            |

## Classes

A component's root class is `seocart-{component}`; its parts are `seocart-{component}__{part}`;
its variants are `seocart-{component}--{variant}`. A transient state is an `is-{state}` class
on the element it describes. Other elements inside a component have no stable name.

| Class                           | Kind      | Of                    | Use                                                                                         |
| ------------------------------- | --------- | --------------------- | ------------------------------------------------------------------------------------------- |
| `seocart-price`                 | component |                       | A product's price                                                                           |
| `seocart-price__amount`         | part      | `seocart-price`       | The selected variant's price                                                                |
| `seocart-price__compare-at`     | part      | `seocart-price`       | The price before a reduction, struck through; hidden for a variant that has none            |
| `seocart-money`                 | component |                       | Any amount of money: figures of one width, so amounts line up                               |
| `seocart-variant-picker`        | component |                       | A product's options                                                                         |
| `seocart-options`               | component |                       | One option axis: a fieldset                                                                 |
| `seocart-options__legend`       | part      | `seocart-options`     | The axis's name                                                                             |
| `seocart-options__current`      | part      | `seocart-options`     | The value selected on the axis, after its name                                              |
| `seocart-options__values`       | part      | `seocart-options`     | The axis's values                                                                           |
| `seocart-pill`                  | component |                       | One option value: a radio button in a label                                                 |
| `seocart-pill__input`           | part      | `seocart-pill`        | The radio button, laid over the pill                                                        |
| `seocart-pill__swatch`          | part      | `seocart-pill`        | The value's colour                                                                          |
| `seocart-pill__image`           | part      | `seocart-pill`        | The value's image                                                                           |
| `seocart-pill__label`           | part      | `seocart-pill`        | The value's name                                                                            |
| `is-unavailable`                | state     | `seocart-pill`        | The value makes no variant that can be bought with the other axes' values; still selectable |
| `seocart-add-to-cart`           | component |                       | The add-to-cart form                                                                        |
| `seocart-add-to-cart__quantity` | part      | `seocart-add-to-cart` | The quantity's label and control                                                            |
| `seocart-add-to-cart__label`    | part      | `seocart-add-to-cart` | The quantity's label                                                                        |
| `seocart-add-to-cart__status`   | part      | `seocart-add-to-cart` | The status region: what an add did                                                          |
| `seocart-qty`                   | component |                       | A quantity: a number between two buttons                                                    |
| `seocart-qty__step`             | part      | `seocart-qty`         | The button that lowers or raises the number                                                 |
| `seocart-qty__input`            | part      | `seocart-qty`         | The number                                                                                  |
| `seocart-button`                | component |                       | A button                                                                                    |
| `seocart-button--block`         | variant   | `seocart-button`      | As wide as its container                                                                    |
| `seocart-button--large`         | variant   | `seocart-button`      | Taller, with larger text                                                                    |
| `seocart-notice`                | component |                       | A sentence for the shopper; it has no box while it is empty                                 |
| `seocart-notice--error`         | variant   | `seocart-notice`      | An error: the alert region of the add-to-cart form                                          |
| `seocart-placeholder`           | component |                       | Where a product block sits in the editor when no product is in its context                  |
| `seocart-visually-hidden`       | component |                       | Text read by assistive technology and not shown                                             |
| `is-pending`                    | state     | `seocart-price`       | Reserved: a price waiting for the shopper's own price to arrive, shown as a skeleton        |

A button's states are its attributes: `aria-disabled="true"` when the selected variant cannot be
added, `aria-busy="true"` while an add is being sent. The block's root also carries the class
WordPress gives every block, such as `wp-block-seocart-product-price`, and any class the merchant
adds in the editor; those are WordPress's, not part of this list.
