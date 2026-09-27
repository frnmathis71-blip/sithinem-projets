using System;
using System.Drawing;
using System.Drawing.Printing;

// Uses the installed Windows driver, not a brand-specific ESC/POS dialect.
public static class SithinemTicketPrinter
{
    public static void Print(string printer, int widthMm, string title, string text)
    {
        using (var document = new PrintDocument())
        using (var font = new Font(FontFamily.GenericMonospace, 9))
        using (var format = new StringFormat(StringFormat.GenericDefault))
        {
            document.PrinterSettings.PrinterName = printer;
            if (!document.PrinterSettings.IsValid)
                throw new InvalidOperationException("Imprimante Windows introuvable : " + printer);
            document.PrinterSettings.Copies = 1;
            document.PrintController = new StandardPrintController();
            document.DocumentName = title;
            document.OriginAtMargins = true;
            document.DefaultPageSettings.Landscape = false;
            int width = (int)Math.Round(widthMm / 25.4 * 100);
            int margin = 16; // 4 mm on each side.
            document.DefaultPageSettings.Margins = new Margins(margin, margin, margin, margin);
            format.FormatFlags = StringFormatFlags.LineLimit;
            using (var graphics = document.PrinterSettings.CreateMeasurementGraphics())
            {
                graphics.PageUnit = GraphicsUnit.Display;
                var size = graphics.MeasureString(text, font, width - margin * 2, format);
                int height = Math.Max(200, (int)Math.Ceiling(size.Height) + margin * 2 + 20);
                document.DefaultPageSettings.PaperSize = new PaperSize("Sithinem ticket", width, Math.Min(height, 32700));
            }
            int offset = 0;
            document.PrintPage += (sender, args) =>
            {
                args.Graphics.PageUnit = GraphicsUnit.Display;
                float safeWidth = Math.Min(width - margin * 2, args.MarginBounds.Width);
                float safeHeight = args.MarginBounds.Height;
                if (safeWidth <= 0 || safeHeight <= 0)
                    throw new InvalidOperationException("Le format papier du pilote est incompatible avec le ticket.");
                string remaining = text.Substring(offset);
                int characters, lines;
                args.Graphics.MeasureString(remaining, font, new SizeF(safeWidth, safeHeight), format, out characters, out lines);
                if (characters <= 0)
                    throw new InvalidOperationException("Impossible de faire tenir le texte sur le papier configuré.");
                args.Graphics.DrawString(remaining.Substring(0, characters), font, Brushes.Black,
                    new RectangleF(0, 0, safeWidth, safeHeight), format);
                offset += characters;
                args.HasMorePages = offset < text.Length;
            };
            document.Print();
        }
    }
}
