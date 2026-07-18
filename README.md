# FOTOhub AI for PrestaShop

Official PrestaShop module for integrating **FOTOhub AI** image generation into your e-commerce store. Generate AI product photos, remove backgrounds, and bulk-process your entire catalog.

**Requires:** PrestaShop 8.0+  
**License:** MIT  
**Version:** 1.0.0

## Features

- **AI Image Generation** — Generate professional product photos from text prompts directly on the product edit page
- **Background Removal** — Remove backgrounds from existing product images with one click
- **Image Upscaling** — Upscale low-resolution product images (2x or 4x)
- **Bulk Processing** — Select multiple products and process them in batch (generate, remove bg, upscale)
- **Auto-Generate** — Optionally auto-generate images when saving products without photos
- **Encrypted API Key Storage** — Your API key is encrypted with AES-256-CBC before storage

## Installation

### Via PrestaShop Module Manager (Recommended)

1. Download the latest release as a `.zip` file
2. In your PrestaShop admin, go to **Modules > Module Manager**
3. Click **Upload a module** and select the zip file
4. The module will be installed automatically

### Manual Installation

1. Download or clone this repository
2. Copy the `fotohubai/` directory to your PrestaShop's `modules/` directory:
   ```bash
   cp -r fotohubai/ /path/to/prestashop/modules/
   ```
3. In your PrestaShop admin, go to **Modules > Module Manager**
4. Search for "FOTOhub" and click **Install**

## Configuration

1. After installation, go to **Modules > FOTOhub AI** (or click Configure)
2. Enter your **FOTOhub API Key** — get one at [fotohub.app/settings/api](https://fotohub.app/settings/api)
3. Select your **Default Model** (SeeDream 5.0 recommended for product photos)
4. Set **Default Image Size** (1024x1024 is recommended)
5. Click **Test Connection** to verify your API key works
6. Click **Save**

## Usage

### Generating Images on Product Pages

1. Edit any product in your catalog
2. Scroll to the **FOTOhub AI — Image Generation** panel
3. Optionally enter a custom prompt (or leave empty to auto-generate from product data)
4. Click **Generate AI Photo**
5. The generated image is automatically added to the product's image gallery

### Bulk Processing

1. Go to **Modules > FOTOhub AI > Bulk Processing**
2. Select products from the list using checkboxes
3. Choose a bulk action:
   - **Generate AI Images** — creates new product photos
   - **Remove Backgrounds** — processes existing cover images
   - **Upscale Images (2x)** — upscales existing cover images
4. Confirm the action
5. View results with thumbnails and status per product

### Auto-Generate on Save

When enabled in settings, the module will automatically generate an AI image whenever you save a product that has no images. This is useful when importing products in bulk.

## API Reference

This module uses the FOTOhub API. Full documentation:  
[docs.fotohub.app/integrations/prestashop](https://docs.fotohub.app/integrations/prestashop)

### Endpoints Used

| Endpoint | Description |
|----------|-------------|
| `POST /v1/images/generate` | Generate image from prompt |
| `POST /v1/images/remove-background` | Remove image background |
| `POST /v1/images/upscale` | Upscale image resolution |
| `GET /v1/account/balance` | Check credit balance |
| `GET /v1/models` | List available models |

## Requirements

- PrestaShop 8.0 or higher
- PHP 8.0 or higher
- cURL extension (recommended) or `allow_url_fopen` enabled
- Valid FOTOhub API key with credits

## No External Dependencies

This module uses PrestaShop's built-in HTTP methods (cURL or `Tools::file_get_contents`). No Composer packages or external libraries are required.

## Supported Models

| Model | Best For |
|-------|----------|
| `seedream-5-0-260128` | Product photos (recommended) |
| `flux-1-1-pro` | Creative/artistic images |
| `flux-1-1-pro-ultra` | Ultra-high quality |
| `ideogram-v3` | Text in images |
| `recraft-v3` | Illustrations and icons |
| `stable-diffusion-xl` | General purpose |

## Hooks

The module registers the following PrestaShop hooks:

- `displayAdminProductsExtra` — Adds the "Generate AI Photo" panel to product edit pages
- `actionAdminProductsControllerSaveAfter` — Auto-generates images on product save (when enabled)
- `displayBackOfficeHeader` — Loads admin CSS

## Troubleshooting

**"Connection failed" on Test Connection**
- Verify your API key is correct
- Ensure your server can make outbound HTTPS requests to `apis.fotohub.app`
- Check if cURL or `allow_url_fopen` is enabled in PHP

**Images not appearing after generation**
- Check PrestaShop's log for errors (Advanced Parameters > Logs)
- Ensure the `img/p/` directory is writable
- Verify your credit balance at [fotohub.app/dashboard](https://fotohub.app/dashboard)

**Bulk processing is slow**
- Each image generation takes 5-30 seconds depending on the model
- Process in smaller batches (10-20 products at a time)
- Consider using a faster model for bulk operations

## Support

- Documentation: [docs.fotohub.app](https://docs.fotohub.app)
- Email: support@fotohub.app
- Issues: Report via your FOTOhub dashboard

## License

MIT License - see [LICENSE](LICENSE) file.
