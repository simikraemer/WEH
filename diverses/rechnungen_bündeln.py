# .exe mit manueller Einbindung von poppler/bin aus diesem .py erstellen

import os
from tkinter import Tk, filedialog, messagebox
from PIL import Image, ImageDraw, ImageFont
from pdf2image import convert_from_path
from datetime import datetime

# Poppler-Pfad hinzufügen
poppler_path = os.path.join(os.path.dirname(__file__), "poppler/bin")

def select_folder():
    # Hauptfenster initialisieren
    root = Tk()
    root.withdraw()  # Hauptfenster verstecken
    
    # Ordner auswählen
    folder_path = filedialog.askdirectory(title="Wähle einen Ordner aus")
    if not folder_path:
        return
    
    # Verarbeitung starten
    process_images(folder_path)


def process_images(folder):
    # Zielordner erstellen
    timestamp = datetime.now().strftime("%Y%m%d_%H%M%S")
    destination_folder = os.path.join(folder, f"{timestamp}_converted_images")
    os.makedirs(destination_folder, exist_ok=True)
    
    # Unterstützte Formate
    supported_extensions = {".png", ".jpg", ".jpeg", ".pdf"}
    for file in os.listdir(folder):
        file_path = os.path.join(folder, file)
        if os.path.isfile(file_path):
            _, ext = os.path.splitext(file.lower())
            if ext in supported_extensions:
                process_file(file_path, destination_folder)
    
    # Windows Explorer öffnen
    os.startfile(destination_folder)


def process_file(file_path, destination_folder):
    _, ext = os.path.splitext(file_path.lower())
    try:
        if ext in {".png", ".jpg", ".jpeg"}:
            # JPG-Dateien direkt kopieren und Titel hinzufügen
            img = Image.open(file_path)
            img = img.convert("RGB")  # Sicherstellen, dass es RGB ist
            img = add_title_to_image(img, os.path.basename(file_path))
            save_path = os.path.join(destination_folder, os.path.basename(file_path))
            img.save(save_path, "JPEG")
        elif ext == ".pdf":
            # PDF-Seiten in Bilder umwandeln und Titel hinzufügen
            images = convert_from_path(file_path, poppler_path=poppler_path)
            for page_num, img in enumerate(images):
                img = add_title_to_image(img, f"{os.path.basename(file_path)} - Seite {page_num + 1}")
                save_path = os.path.join(destination_folder, f"{os.path.splitext(os.path.basename(file_path))[0]}_page{page_num + 1}.jpg")
                img.save(save_path, "JPEG")
    except Exception as e:
        print(f"Fehler bei der Verarbeitung von '{file_path}': {e}")

def add_title_to_image(img, title):
    # Bildabmessungen
    img_width, img_height = img.size
    font_size = max(20, img_width // 30)  # Dynamische Schriftgröße basierend auf der Bildbreite
    try:
        font = ImageFont.truetype("arial.ttf", font_size)  # Verwende Arial (oder andere verfügbare Schrift)
    except IOError:
        font = ImageFont.load_default()  # Fallback, wenn die Schriftart nicht verfügbar ist

    # Textgröße berechnen
    draw = ImageDraw.Draw(img)
    text_bbox = draw.textbbox((0, 0), title, font=font)  # Ersetzt textsize
    text_width = text_bbox[2] - text_bbox[0]
    text_height = text_bbox[3] - text_bbox[1]

    # Platz für den Text schaffen (weißer Balken)
    new_img_height = img_height + text_height + 30  # Zusätzlicher Platz für den Balken
    new_img = Image.new("RGB", (img_width, new_img_height), "#11a50d")
    new_img.paste(img, (0, 0))

    # Text zentrieren
    text_x = (img_width - text_width) // 2
    text_y = img_height

    # Text zeichnen
    draw = ImageDraw.Draw(new_img)
    draw.text((text_x, text_y), title, fill="white", font=font)

    return new_img

if __name__ == "__main__":
    try:
        select_folder()
    except Exception as e:
        print(f"Ein Fehler ist aufgetreten: {e}")
