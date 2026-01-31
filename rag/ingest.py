#!/usr/bin/env python3
"""
EmberCortex Document Ingestion CLI
Ingest documents into ChromaDB collections
"""
import argparse
import sys
import chromadb
from pathlib import Path

from llama_index.core import (
    VectorStoreIndex,
    SimpleDirectoryReader,
    StorageContext,
    Settings,
)
from llama_index.core.node_parser import SentenceSplitter
from llama_index.vector_stores.chroma import ChromaVectorStore
from llama_index.embeddings.huggingface import HuggingFaceEmbedding

import config

# Initialize embedding model
Settings.embed_model = HuggingFaceEmbedding(
    model_name=config.EMBED_MODEL,
    trust_remote_code=True,
)
Settings.chunk_size = config.CHUNK_SIZE
Settings.chunk_overlap = config.CHUNK_OVERLAP


def ingest_directory(input_dir: str, collection_name: str, description: str = ""):
    """Ingest documents from a directory into a ChromaDB collection."""
    input_path = Path(input_dir)
    if not input_path.is_dir():
        print(f"Error: Directory not found: {input_dir}")
        sys.exit(1)
    
    print(f"Initializing ChromaDB at {config.CHROMA_DIR}...")
    db = chromadb.PersistentClient(path=str(config.CHROMA_DIR))
    
    print(f"Creating/getting collection '{collection_name}'...")
    chroma_collection = db.get_or_create_collection(
        name=collection_name,
        metadata={"description": description, "source": str(input_path.absolute())}
    )
    
    # Create vector store
    vector_store = ChromaVectorStore(chroma_collection=chroma_collection)
    storage_context = StorageContext.from_defaults(vector_store=vector_store)
    
    # Load documents
    print(f"Loading documents from {input_dir}...")
    documents = SimpleDirectoryReader(
        input_dir=input_dir,
        recursive=True,
        required_exts=[
            ".py", ".md", ".txt", ".rst", ".json", ".yaml", ".yml",
            ".js", ".ts", ".jsx", ".tsx", ".html", ".css",
            ".sh", ".bash", ".php", ".go", ".rs", ".c", ".cpp", ".h",
        ],
    ).load_data()
    
    if not documents:
        print("No documents found!")
        sys.exit(1)
    
    print(f"Loaded {len(documents)} documents")
    
    # Create index
    print("Creating embeddings and indexing...")
    index = VectorStoreIndex.from_documents(
        documents,
        storage_context=storage_context,
        transformations=[SentenceSplitter(chunk_size=config.CHUNK_SIZE, chunk_overlap=config.CHUNK_OVERLAP)],
        show_progress=True,
    )
    
    final_count = chroma_collection.count()
    print(f"\nDone! Ingested {len(documents)} documents into '{collection_name}'")
    print(f"Total chunks in collection: {final_count}")


def list_collections():
    """List all collections."""
    db = chromadb.PersistentClient(path=str(config.CHROMA_DIR))
    collections = db.list_collections()
    
    if not collections:
        print("No collections found.")
        return
    
    print("\nCollections:")
    print("-" * 60)
    for c in collections:
        desc = c.metadata.get("description", "No description")
        print(f"  {c.name}: {c.count()} chunks - {desc}")
    print()


def delete_collection(name: str):
    """Delete a collection."""
    db = chromadb.PersistentClient(path=str(config.CHROMA_DIR))
    try:
        db.delete_collection(name)
        print(f"Deleted collection '{name}'")
    except Exception as e:
        print(f"Error: {e}")
        sys.exit(1)


def main():
    parser = argparse.ArgumentParser(description="EmberCortex Document Ingestion")
    subparsers = parser.add_subparsers(dest="command", help="Commands")
    
    # Ingest command
    ingest_parser = subparsers.add_parser("ingest", help="Ingest documents from a directory")
    ingest_parser.add_argument("directory", help="Directory containing documents")
    ingest_parser.add_argument("collection", help="Collection name")
    ingest_parser.add_argument("-d", "--description", default="", help="Collection description")
    
    # List command
    subparsers.add_parser("list", help="List all collections")
    
    # Delete command
    delete_parser = subparsers.add_parser("delete", help="Delete a collection")
    delete_parser.add_argument("collection", help="Collection name to delete")
    
    args = parser.parse_args()
    
    if args.command == "ingest":
        ingest_directory(args.directory, args.collection, args.description)
    elif args.command == "list":
        list_collections()
    elif args.command == "delete":
        delete_collection(args.collection)
    else:
        parser.print_help()


if __name__ == "__main__":
    main()
